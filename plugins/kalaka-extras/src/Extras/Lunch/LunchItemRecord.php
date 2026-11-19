<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Extras\Api\LunchItem;
use Kalaka\Foundation\Api\Money\Money;

/** A row of `kalaka_extras_lunch_items`: one lunch item of an event, at its position (0 first). */
final class LunchItemRecord
{
    /** @param array<array-key, mixed>|null $condition */
    public function __construct(
        public readonly int $eventId,
        public readonly string $key,
        public int $position,
        public string $label,
        public Money $amount,
        public ?array $condition,
        public int $version = 0,
    ) {
    }

    public function item(): LunchItem
    {
        return new LunchItem($this->key, $this->label, $this->amount, $this->condition);
    }
}
