<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\PriceLadder\Api\Tier;

/** A row of `kalaka_price_ladder_tiers`: one tier of an event's ladder, at its position (0 first). */
final class TierRecord
{
    /** @param array<array-key, mixed>|null $condition */
    public function __construct(
        public readonly int $eventId,
        public readonly string $key,
        public int $position,
        public string $label,
        public Money $amount,
        public ?array $condition,
        public ?\DateTimeImmutable $until,
        public int $version = 0,
    ) {
    }

    public function tier(): Tier
    {
        return new Tier($this->key, $this->label, $this->amount, $this->condition, $this->until);
    }
}
