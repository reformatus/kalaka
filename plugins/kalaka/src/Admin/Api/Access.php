<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\Grant\Grant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What an admin-side actor may reach, for the screens and the fine check: full access, the events where
 * they hold a grant, and the views they hold there as `HeldView` handles for `ViewQuery`
 * (foundation.md, "Views and grants"). It reads only Admin's own tables and WordPress's capabilities.
 *
 * [UNAPPROVED S36-7]
 */
final class Access
{
    /** @var array<int, bool> whether a user may reach the admin side, by user id, for this request */
    private static array $reach = [];

    public function __construct(
        private readonly GrantQuery $grants = new GrantQuery(),
        private readonly ViewQuery $views = new ViewQuery(),
    ) {
    }

    /** Whether the actor has full access: every event, every view, every action. */
    public function isFull(Actor $actor): bool
    {
        return AdminAccess::isFull($actor);
    }

    /**
     * [UNAPPROVED S36-2] Whether the WordPress user may reach the admin side's abilities and routes at
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
     * view is missing, or whose bound value no longer matches the view's parameter, holds nothing and is
     * left out. Full access holds the master view instead (`master()`); its own grants are listed too.
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
            $parameter = $shape !== null && $shape->hasParameter();
            if ($parameter !== ($grant['bound_value'] !== null)) {
                continue;
            }
            $held[] = new HeldView(
                $eventId,
                $grant['id'],
                $grant['view_id'],
                $grant['name'],
                $shape,
                $grant['bound_value']
            );
        }

        return $held;
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

        return new HeldView($eventId, null, null, null, null, null);
    }

    /**
     * The fine check of a field change, run inside `execute`: whether the actor may write the column
     * (`field:<key>`) of the registration, which the run must already hold locked, so the view's filter is
     * read as it is under the lock. Full access may; otherwise a held view must list the column read-write
     * and contain the registration.
     *
     * [UNAPPROVED S36-11]
     *
     * @throws SystemFailure when the run does not hold the registration's row lock
     */
    public function mayWrite(Actor $actor, RegistrationKey $key, string $column, Context $context): bool
    {
        if (!$context->holdsRegistration($key)) {
            $error = new SystemFailure(null, new \LogicException('The fine check needs the registration locked.'));
            throw $error;
        }
        if ($this->isFull($actor)) {
            return true;
        }

        foreach ($this->heldViews($actor, $key->eventId) as $view) {
            $shape = $view->shape();
            $writes = $shape === null ? str_starts_with($column, 'field:') : $shape->writes($column);
            if ($writes && $this->views->contains($view, [$key])) {
                return true;
            }
        }

        return false;
    }

    private static function userIdOf(Actor $actor): ?int
    {
        if ($actor instanceof UserActor || $actor instanceof CliActor) {
            return $actor->userId;
        }

        return null;
    }
}
