<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;

/**
 * The seat claim of a submit's price tier: one claim, in `price_tier`, when the tier the registrant chose has a
 * limit. It locks nothing, counts nothing and writes nothing: the submit locks the pool (a full tier refuses
 * the submit, with the message on that field) and takes the seat when the registration becomes `registered`.
 * [UNAPPROVED N1]
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
            if ($tier->key === $chosen && $tier->limit !== null) {
                $pool = new TierSeatPool($event->id, $tier->key, $this->tiers);

                return [new SeatClaim(PriceLadderComponent::FIELD, $pool)];
            }
        }

        return [];
    }
}
