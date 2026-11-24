<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;

/**
 * The seat claim of a submit's price tier: one claim, in `price_tier`, for the tier the registrant chose, limited or
 * not (every chooser holds a seat row, so a limit added later counts correctly). It locks nothing, counts nothing
 * and writes nothing: the submit locks the pool (a full tier refuses the submit, with the message on that field)
 * and takes the seat when the registration becomes `registered`. Its pools are every tier's, for the form host's
 * `seats_left` variable. [UNAPPROVED N1]
 */
final class TierClaims implements SeatClaimSource
{
    public function __construct(private readonly TierRepository $tiers = new TierRepository())
    {
    }

    public function claims(Event $event, ValidatedAnswers $answers): array
    {
        $chosen = $answers->stored()[PriceLadderComponent::FIELD] ?? null;
        if ($chosen === null) {
            return [];
        }
        foreach ($this->tiers->forEvent($event->id) as $tier) {
            if ($tier->key === $chosen) {
                $pool = new TierSeatPool($event->id, $tier->key, $this->tiers);

                return [new SeatClaim(PriceLadderComponent::FIELD, $pool)];
            }
        }

        return [];
    }

    public function pools(Event $event): array
    {
        $entries = [];
        foreach ($this->tiers->forEvent($event->id) as $tier) {
            $entries[] = new PoolEntry(
                PriceLadderComponent::FIELD,
                $tier->key,
                new TierSeatPool($event->id, $tier->key, $this->tiers)
            );
        }

        return $entries;
    }
}
