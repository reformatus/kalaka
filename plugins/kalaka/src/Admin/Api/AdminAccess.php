<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\Grant\Grant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;

/**
 * The coarse permission check of the admin side, called by the runner before an admin
 * use case begins: foundation.md, "The use-case runner" and "Views and grants".
 */
final class AdminAccess
{
    /**
     * The WordPress capability that gives full access to the admin side. It is given per user, never to
     * a role; administrators do not get it by their role (S36-1, owner ruling 2026-11-10).
     */
    public const FULL_ACCESS = 'kalaka_full_access';

    /**
     * Passes the system, the CLI without a user, and a user with full access, or with a grant on the
     * use case's event that holds the call (see `grantHolds()`); refuses every other admin actor.
     *
     * @param array<string, mixed> $input the sanitized input
     * @throws PermissionDenied
     */
    public static function check(AdminUseCase $useCase, array $input, Actor $actor): void
    {
        if (self::isFull($actor)) {
            return;
        }

        if (($actor instanceof UserActor || $actor instanceof CliActor) && $actor->userId !== null) {
            if (self::grantHolds($useCase, $input, $actor->userId)) {
                return;
            }
        }

        throw new PermissionDenied();
    }

    /**
     * Whether the actor has full access: the system, the CLI without a user, and a user with the
     * capability (on multisite, a super admin too, as `user_can()` passes them).
     */
    public static function isFull(Actor $actor): bool
    {
        if ($actor instanceof SystemActor) {
            return true;
        }
        if ($actor instanceof UserActor || $actor instanceof CliActor) {
            return $actor->userId === null || self::userIsFull($actor->userId);
        }

        return false;
    }

    /** Whether the WordPress user has full access. */
    public static function userIsFull(int $userId): bool
    {
        return $userId > 0 && user_can($userId, self::FULL_ACCESS);
    }

    /**
     * Whether one grant of the user on the use case's event holds the call. A site-level use case is
     * never held by a grant.
     *
     * [UNAPPROVED S36-3] Deny by default: the use case names an action the grant carries, or it is a
     * field change (no action) with at least one target; in both cases the grant's view, with its bound
     * value, holds every target as the registrations are now. A use case with no action and no target is
     * full access only.
     *
     * @param array<string, mixed> $input
     */
    private static function grantHolds(AdminUseCase $useCase, array $input, int $userId): bool
    {
        $eventId = $useCase->event($input);
        if ($eventId === null) {
            return false;
        }
        $action = $useCase->action();
        $targets = $useCase->targets($input);
        if ($action === null && $targets === []) {
            return false;
        }
        // An action that is no longer offered counts as absent from every grant.
        if ($action !== null && !ActionCatalogue::current()->has($action)) {
            return false;
        }

        $views = new ViewQuery();
        foreach ((new GrantQuery())->forUser($eventId, $userId) as $grant) {
            if ($action !== null && !in_array($action, $grant['actions'], true)) {
                continue;
            }
            $shape = Grant::viewShape($grant);
            if ($shape === false) {
                continue;
            }
            if ($views->filterHolds($eventId, $shape, $grant['bound_value'], $targets)) {
                return true;
            }
        }

        return false;
    }
}
