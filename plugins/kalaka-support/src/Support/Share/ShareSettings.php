<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

/** A row of `kalaka_support_share`: the event's congregation share is on. */
final class ShareSettings
{
    public function __construct(
        public readonly int $eventId,
        public int $version = 0,
    ) {
    }
}
