<?php

declare(strict_types=1);

namespace Kalaka\Share;

/** A row of `kalaka_share_settings`: the event's congregation share is on. */
final class ShareSettings
{
    public function __construct(
        public readonly int $eventId,
        public int $version = 0,
    ) {
    }
}
