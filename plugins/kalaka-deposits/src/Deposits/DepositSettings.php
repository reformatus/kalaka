<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

/**
 * A row of `kalaka_deposit_settings`: the event's deposit, `amount` decimal text in the event's currency.
 */
final class DepositSettings
{
    public function __construct(
        public readonly int $eventId,
        public string $amount,
        public int $version = 0,
    ) {
    }
}
