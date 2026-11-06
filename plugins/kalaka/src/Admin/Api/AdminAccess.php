<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;

/**
 * The coarse permission check of the admin side, called by the runner before an admin
 * use case begins: foundation.md, "The use-case runner" and "Views and grants".
 */
final class AdminAccess
{
    /** The WordPress capability that gives full access to the admin side. */
    public const FULL_ACCESS = 'kalaka_full_access';

    /**
     * Passes the system, the CLI without a user, and a user with full access;
     * refuses every other admin actor.
     *
     * @param array<string, mixed> $input the sanitized input
     * @throws PermissionDenied
     */
    public static function check(AdminUseCase $useCase, array $input, Actor $actor): void
    {
        if ($actor instanceof SystemActor) {
            return;
        }

        if ($actor instanceof UserActor || $actor instanceof CliActor) {
            // The CLI without --user has full access.
            if ($actor->userId === null || user_can($actor->userId, self::FULL_ACCESS)) {
                return;
            }
        }

        throw new PermissionDenied();
    }
}
