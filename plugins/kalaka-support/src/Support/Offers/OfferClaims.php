<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;

/**
 * The seat claims of a registration's support offers: one claim, in the offer's own field, for each offer, limited
 * or not, the registration is entitled to now (a chooser always holds its seat row): its consent is ticked and,
 * for an offer that must be requested, the answer is not `refused`. A refused offer gives no claim, whether the
 * values are a submit's answers or a registration's stored values asked again at admission or restore, so a
 * restore never retakes the seat of a refusal. It locks nothing, counts nothing and writes nothing: the submit
 * locks the pools (a full one refuses the submit, with the message on that field) and takes the seats when the
 * registration becomes `registered`. `pools()` lists the pool of every offer of the event, for the form host's
 * `seats_left.<offer key>.1`. [UNAPPROVED N96, N103]
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
            if (
                ($stored[$offer->key] ?? '') === '1'
                && ($stored[$offer->key . SetSupportOffers::ANSWER_SUFFIX] ?? '') !== 'refused'
            ) {
                $claims[] = new SeatClaim($offer->key, new OfferSeatPool($event->id, $offer->key, $this->offers));
            }
        }

        return $claims;
    }

    public function pools(Event $event): array
    {
        $entries = [];
        foreach ($this->offers->forEvent($event->id) as $offer) {
            $entries[] = new PoolEntry(
                $offer->key,
                '1',
                new OfferSeatPool($event->id, $offer->key, $this->offers)
            );
        }

        return $entries;
    }
}
