<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;

/**
 * The seat claims of a submit's support offers: one claim, in the offer's own field, for each limited offer
 * whose consent the registrant ticked. It locks nothing, counts nothing and writes nothing: the submit locks
 * the pools (a full one refuses the submit, with the message on that field) and takes the seats when the
 * registration becomes `registered`. [UNAPPROVED N4]
 */
final class OfferClaims implements SeatClaimSource
{
    public function __construct(private readonly OfferRepository $offers = new OfferRepository())
    {
    }

    public function claims(Event $event, ValidatedAnswers $answers): array
    {
        $stored = $answers->stored();
        $claims = [];
        foreach ($this->offers->forEvent($event->id) as $offer) {
            if ($offer->limit !== null && ($stored[$offer->key] ?? '') === '1') {
                $claims[] = new SeatClaim($offer->key, new OfferSeatPool($event->id, $offer->key, $this->offers));
            }
        }

        return $claims;
    }
}
