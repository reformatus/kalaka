<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Money\Money;

/** The current fixed price of an event; no record means no fixed price. */
final class FixedPriceRecord
{
    public function __construct(
        public readonly int $eventId,
        public Money $amount,
        public int $version = 0,
    ) {
    }
}
