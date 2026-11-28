<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Forms\Api\SlotRequirement;

/**
 * An event with tiers requires `price_tier` in its registration form (the owner's ruling 2026-11-19): without
 * the field a registration could not choose a tier.
 */
final class LadderSlotRequirement implements SlotRequirement
{
    public function __construct(private readonly TierRepository $tiers = new TierRepository())
    {
    }

    public function requiredKeys(int $eventId): array
    {
        return $this->tiers->forEvent($eventId) === [] ? [] : [PriceLadderComponent::FIELD];
    }
}
