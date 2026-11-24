<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Pause\MailState;

/**
 * The site's mail at a glance: the pause, if any, the pending deliveries of every event, the errors in a row and
 * the hourly budget (the sender's state row).
 */
final readonly class SiteMailStats
{
    public function __construct(
        public ?PauseInfo $paused,
        public int $queued,
        public int $errorsInRow = 0,
        public int $hourlyBudget = MailState::DEFAULT_HOURLY_BUDGET,
    ) {
    }
}
