<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\FullAccess\FullAccessQuery;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * When the plugin is activated and no user holds `kalaka_full_access`, the activating user
 * gets it (`GrantFullAccess`, run as the system, so it is audited). That covers the first activation and
 * the recovery when every holder is gone; while anyone holds it (by `FullAccessQuery`'s holder rule, a role
 * carrying it included), activating again gives nobody anything.
 */
final class FullAccessOnActivation
{
    /** Called by the activation hook, after the boot. */
    public static function grant(int $userId): void
    {
        if ($userId <= 0 || (new FullAccessQuery())->anyHolder()) {
            return;
        }
        Boundary::background('granting full access on activation', static function () use ($userId): void {
            Runner::current()->run(new GrantFullAccess(), ['user_id' => $userId], new SystemActor());
        });
    }
}
