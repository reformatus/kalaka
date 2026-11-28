<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One tier of an event's price ladder (money.md, "Core money scope"): its key, the label the organizers gave
 * it and its amount in the event's currency. A tier has no condition and no deadline: it is chosen only
 * through the declared field `price_tier` (the owner's ruling 2026-11-19). A tier may have a limit of people:
 * then it is a seat pool (`tier.<key>`) and a full tier cannot be chosen (topic 15, A1).
 */
final readonly class Tier
{
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public ?int $limit = null,
    ) {
    }
}
