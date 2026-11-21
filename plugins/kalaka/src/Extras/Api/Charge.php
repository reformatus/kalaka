<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One extra charge of an event (money.md, "Core money scope"): its key, the label organizers gave it and its
 * amount in the event's currency. The participant field whose key equals the charge's key calls it, and the
 * amount is multiplied by the answer's whole value: a consent's `1` charges it once, and a number answer of
 * a positive whole `n` charges it `n` times. It has no condition of its own, and a key no field uses charges
 * nobody. It is its own ledger line, `extras.<key>`.
 */
final readonly class Charge
{
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
    ) {
    }
}
