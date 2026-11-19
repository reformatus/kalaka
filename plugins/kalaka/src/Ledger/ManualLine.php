<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;

/** What the use cases that write a line by hand share: who recorded it, and how many decimals an amount has. */
final class ManualLine
{
    /** Who recorded it, as the line's source: the WordPress user, or the system for a run without one. */
    public static function sourceOf(Actor $actor): Subject
    {
        $userId = $actor instanceof UserActor || $actor instanceof CliActor ? $actor->userId : null;

        if ($userId === null) {
            return new Subject('system', null, 'system');
        }

        return new Subject('wp_user', null, (string) $userId);
    }

    /** The significant decimals of a plain decimal string. */
    public static function decimals(string $amount): int
    {
        $point = strpos($amount, '.');

        return $point === false ? 0 : strlen(rtrim(substr($amount, $point + 1), '0'));
    }
}
