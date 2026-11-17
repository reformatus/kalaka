<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Forms\Api\Conditional;
use Kalaka\Foundation\Api\Money\Money;

/**
 * One lunch item of an event (money.md, "Core money scope"): its key, the label the organizers gave it, its
 * amount in the event's currency and its condition in the one condition format (null: for everyone). The
 * participant pays every item whose condition holds; the congregation share never covers it.
 */
final readonly class LunchItem implements Conditional
{
    /** @param array<array-key, mixed>|null $condition */
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public ?array $condition = null,
    ) {
    }

    public function availabilityKey(): string
    {
        return $this->key;
    }

    public function availabilityCondition(): ?array
    {
        return $this->condition;
    }
}
