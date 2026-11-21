<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One extra charge of an event (money.md, "Core money scope", topic 7): its key, the label organizers gave
 * it, its amount in the event's currency, and the participant field whose answer calls it. The amount is
 * multiplied by the answer's whole value: a consent's `1` charges it once, and a number answer of a positive
 * whole `n` charges it `n` times. It has no condition of its own.
 */
final readonly class Charge
{
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public string $field,
    ) {
    }
}
