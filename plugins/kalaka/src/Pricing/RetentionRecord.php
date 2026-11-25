<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

/** The event's chosen retention rule, by its ID; no record means the default. */
final class RetentionRecord
{
    public function __construct(
        public readonly int $eventId,
        public string $rule,
        public int $version = 0,
    ) {
    }
}
