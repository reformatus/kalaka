<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/** The site's mail at a glance: the pause, if any, and the pending deliveries of every event. */
final readonly class SiteMailStats
{
    public function __construct(
        public ?PauseInfo $paused,
        public int $queued,
    ) {
    }
}
