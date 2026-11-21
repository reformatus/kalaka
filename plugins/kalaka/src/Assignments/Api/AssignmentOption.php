<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Forms\Api\Conditional;

/**
 * One option of an assignment set (modules-and-extensions.md, "Assignment sets"): IVK's module. Its key is
 * derived once from the label (`Forms\Api\KeyDerivation::value()`) and never changes. Whether it is offered
 * is decided by its eligibility condition, in the one condition format, through the one availability base
 * (`Forms\Api\Availability`, S76); it never evaluates the condition itself. The capacity limits only this
 * option, and only at confirmation (request mode); `leaders` and `place` are display text.
 */
final readonly class AssignmentOption implements Conditional
{
    /** @param array<array-key, mixed>|null $condition the eligibility condition; null: everybody is eligible */
    public function __construct(
        public int $setId,
        public string $key,
        public string $label,
        public ?int $capacity,
        public string $leaders,
        public string $place,
        public ?array $condition,
        public int $position,
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
