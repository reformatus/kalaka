<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Pricing\Api\Retention;

/** The event's chosen retention rule; no record means the default. */
final class RetentionRecord
{
    public function __construct(
        public readonly int $eventId,
        public Retention $rule,
        public int $version = 0,
    ) {
    }
}
