<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;

/** The user behind an actor, for the pause's "who set it". */
final class ActorUser
{
    /** The user of a user or WP-CLI actor; null for the system or WP-CLI without `--user`. */
    public static function of(Actor $actor): ?int
    {
        return match (true) {
            $actor instanceof UserActor => $actor->userId,
            $actor instanceof CliActor => $actor->userId,
            default => null,
        };
    }
}
