<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Events\Api\Event;

/**
 * What a time rule may read: one published event and the window (`since`, `until`], both UTC;
 * a null `since` means everything due up to `until`.
 */
final readonly class TimeRuleInput
{
    public function __construct(
        public Event $event,
        public ?\DateTimeImmutable $since,
        public \DateTimeImmutable $until,
    ) {
    }
}
