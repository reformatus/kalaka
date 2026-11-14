<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * [UNAPPROVED N2] When the plugin is activated and no user holds `kalaka_full_access`, the activating user
 * gets it (`GrantFullAccess`, run as the system, so it is audited). That covers the first activation and
 * the recovery when every holder is gone; while anyone holds it, activating again gives nobody anything.
 */
final class FullAccessOnActivation
{
    /** Called by the activation hook, after the boot. */
    public static function grant(int $userId): void
    {
        if ($userId <= 0 || self::anyHolder()) {
            return;
        }
        Boundary::background('granting full access on activation', static function () use ($userId): void {
            Runner::current()->run(new GrantFullAccess(), ['user_id' => $userId], new SystemActor());
        });
    }

    private static function anyHolder(): bool
    {
        $holders = get_users([
            'capability' => AdminAccess::FULL_ACCESS,
            'number' => 1,
            'fields' => 'ID',
            'count_total' => false,
        ]);

        return $holders !== [];
    }
}
