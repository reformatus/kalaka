<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\Grant\Grant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Admin\View\ViewList;
use Kalaka\Admin\View\ViewRepository;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What an admin-side actor may reach, for the screens and the fine check: full access, the events where
 * they hold a grant, and the views they hold there as `HeldView` handles for `ViewQuery`
 * (foundation.md, "Views and grants"). It reads only Admin's own tables and WordPress's capabilities.
 */
final class Access
{
    /** @var array<int, bool> whether a user may reach the admin side, by user id, for this request */
    private static array $reach = [];

    public function __construct(
        private readonly GrantQuery $grants = new GrantQuery(),
        private readonly ViewQuery $views = new ViewQuery(),
        private readonly ViewRepository $viewRepository = new ViewRepository(),
        private readonly ViewList $viewList = new ViewList(),
    ) {
    }

    /** Whether the actor has full access: every event, every view, every action. */
    public function isFull(Actor $actor): bool
    {
        return AdminAccess::isFull($actor);
    }

    /**
     * The one money-view read: whether the actor may see the money of the registration. Full access, or a held view
     * that contains it and whose grant carries money-view or money-write (the owner's ruling 2026-11-18: money-write
     * includes money-view). The detail read and the status-act preview both ask it.
     */
    public function mayViewMoney(Actor $actor, RegistrationKey $key): bool
    {
        if ($this->isFull($actor)) {
            return true;
        }
        foreach ($this->holding($actor, $key) as $held) {
            if (
                in_array(Ledger::VIEW_MONEY, $held['actions'], true)
                || in_array(Ledger::RECORD_MONEY, $held['actions'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the WordPress user may reach the admin side's abilities and routes at
     * all: full access, or at least one grant on any event. One indexed statement, kept for the request.
     * The runner's coarse check still decides each call.
     */
    public static function mayReachAdmin(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        return self::$reach[$userId] ??= AdminAccess::userIsFull($userId) || (new GrantQuery())->anyForUser($userId);
    }

    /** @internal For tests and for the use cases that change grants or full access. */
    public static function forget(): void
    {
        self::$reach = [];
    }

    /**
     * The events where the actor holds at least one grant, ascending. Full access needs no grant and
     * reaches every event; this lists only grants.
     *
     * @return list<int>
     */
    public function grantedEventIds(Actor $actor): array
    {
        $userId = self::userIdOf($actor);

        return $userId === null ? [] : $this->grants->eventIdsOfUser($userId);
    }

    /**
     * The views the actor holds on the event through grants, one per grant, by grant id. A grant whose
     * view is missing or unreadable holds nothing and is left out; a grant without a bound value on a view
     * with a parameter sees the whole view (S36-4, `ViewQuery`). Full access holds the master view instead
     * (`master()`); its own grants are listed too.
     *
     * @return list<HeldView>
     */
    public function heldViews(Actor $actor, int $eventId): array
    {
        $userId = self::userIdOf($actor);
        if ($userId === null) {
            return [];
        }

        $held = [];
        foreach ($this->grants->forUser($eventId, $userId) as $grant) {
            $shape = Grant::viewShape($grant);
            if ($shape === false) {
                continue;
            }
            $held[] = new HeldView(
                $eventId,
                $grant['id'],
                $grant['view_id'],
                $grant['name'],
                $shape,
                $grant['bound_value'],
                $shape === null ? $this->viewList->masterCounts($eventId) : []
            );
        }

        return $held;
    }

    /**
     * The views the actor holds through grants that contain the registration now, each with the actions its
     * grant carries: for the detail read, where a grant's view decides what is shown and its actions what
     * the holder may do. One statement for the grants, and one for each grant's filter. Full access holds
     * the master view (`master()`) and needs no grant; its own grants are listed too.
     *
     * @return list<array{view: HeldView, actions: list<string>}>
     */
    public function holding(Actor $actor, RegistrationKey $key): array
    {
        $userId = self::userIdOf($actor);
        if ($userId === null) {
            return [];
        }

        $holding = [];
        foreach ($this->grants->forUser($key->eventId, $userId) as $grant) {
            $shape = Grant::viewShape($grant);
            if ($shape === false) {
                continue;
            }
            if (!$this->views->filterHolds($key->eventId, $shape, $grant['bound_value'], [$key])) {
                continue;
            }
            $holding[] = [
                'view' => new HeldView(
                    $key->eventId,
                    $grant['id'],
                    $grant['view_id'],
                    $grant['name'],
                    $shape,
                    $grant['bound_value']
                ),
                'actions' => $grant['actions'],
            ];
        }

        return $holding;
    }

    /**
     * The event's master view, through full access only. It does not check that the event exists.
     *
     * @throws PermissionDenied without full access
     */
    public function master(Actor $actor, int $eventId): HeldView
    {
        if (!$this->isFull($actor)) {
            throw new PermissionDenied();
        }

        return new HeldView($eventId, null, null, null, null, null, $this->viewList->masterCounts($eventId));
    }

    /**
     * A stored view of the event as full access opens it, for the grid's tabs (S37): its filter and columns,
     * with no bound value, so a parameter term does not apply and the whole view shows, as for a grant
     * without one (S36-4).
     *
     * @throws PermissionDenied without full access
     * @throws NotFound when the view is not one of the event's
     */
    public function fullView(Actor $actor, int $eventId, int $viewId): HeldView
    {
        if (!$this->isFull($actor)) {
            throw new PermissionDenied();
        }
        $view = $this->viewRepository->find($viewId);
        if ($view === null || $view->eventId !== $eventId || $view->master) {
            throw new NotFound();
        }

        return new HeldView($eventId, null, $view->id, $view->name, $view->shape, null);
    }

    /**
     * Whether the actor may write the column (`field:<key>`) of the registration: full access may; otherwise a
     * held view must list the column read-write and contain the registration. The fine check of a field change
     * passes the run's `Context`, and the registration must already be locked there, so the view's filter is read
     * as it is under the lock. Without a context it is a plain read, for a caller that must decide before the run
     * starts or only displays; the act re-checks under the lock.
     *
     * @throws SystemFailure when a context is given and the run does not hold the registration's row lock
     */
    public function mayWrite(Actor $actor, RegistrationKey $key, string $column, ?Context $context = null): bool
    {
        return $this->writer($actor, $key, $context)($column);
    }

    /**
     * `mayWrite()` for many columns of one registration: the held views that contain it are read once, and the
     * returned test answers for each column (`field:<key>`) by the same rule. For a screen that shows which
     * fields are editable; the act re-checks under the lock.
     *
     * @return \Closure(string): bool
     * @throws SystemFailure when a context is given and the run does not hold the registration's row lock
     */
    public function writer(Actor $actor, RegistrationKey $key, ?Context $context = null): \Closure
    {
        if ($context !== null && !$context->holdsRegistration($key)) {
            $error = new SystemFailure(null, new \LogicException('The fine check needs the registration locked.'));
            throw $error;
        }
        if ($this->isFull($actor)) {
            return static fn (string $column): bool => true;
        }

        $shapes = [];
        foreach ($this->heldViews($actor, $key->eventId) as $view) {
            if ($this->views->contains($view, [$key])) {
                $shapes[] = $view->shape();
            }
        }

        return static function (string $column) use ($shapes): bool {
            foreach ($shapes as $shape) {
                if ($shape === null ? str_starts_with($column, 'field:') : $shape->writes($column)) {
                    return true;
                }
            }

            return false;
        };
    }

    private static function userIdOf(Actor $actor): ?int
    {
        if ($actor instanceof UserActor || $actor instanceof CliActor) {
            return $actor->userId;
        }

        return null;
    }
}
