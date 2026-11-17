<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder\Api;

use Kalaka\Forms\Api\Conditional;
use Kalaka\Foundation\Api\Money\Money;

/**
 * One tier of an event's price ladder (money.md, "Core money scope"): its key, the label the organizers gave
 * it, its amount in the event's currency, its availability condition in the one condition format (null: for
 * everyone) and its deadline, the first instant it no longer applies (the end of its last day in the site
 * time zone, fixed when it is stored; null: no deadline). Its share mode is S29's.
 */
final readonly class Tier implements Conditional
{
    /** @param array<array-key, mixed>|null $condition */
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public ?array $condition = null,
        public ?\DateTimeImmutable $until = null,
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

    /**
     * Whether the deadline lets in a registration that became `registered` at that instant (money.md, "Money
     * settings apply": every time-dependent rule counts from `registered_at`, never from now). One that is
     * not registered is never let in by a tier with a deadline.
     */
    public function openAt(?\DateTimeImmutable $registeredAt): bool
    {
        return $this->until === null || ($registeredAt !== null && $registeredAt < $this->until);
    }
}
