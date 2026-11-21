<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Extras\Api\Charge;
use Kalaka\Foundation\Api\Money\Money;

/**
 * A row of `kalaka_extras_charges`: one extra charge of an event, at its position (0 first).
 */
final class ChargeRecord
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $key,
        public int $position,
        public string $label,
        public Money $amount,
        public int $version = 0,
    ) {
    }

    public function charge(): Charge
    {
        return new Charge($this->key, $this->label, $this->amount);
    }
}
