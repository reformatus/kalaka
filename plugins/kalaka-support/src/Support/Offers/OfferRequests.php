<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\Answer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Request;
use Kalaka\Registrations\Api\RequestKind;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\Seats;

/**
 * The requests of the support offers that must be requested (money.md, "Sponsor and church support"): the offer
 * the registrant selected, in the consent field whose key is the offer's key, is the request `offer:<key>`, until
 * somebody answers it. The answer is written to the offer's own field `<key>_answer` (`approved` or `refused`),
 * this source being its only writer. It reads only the registration's field values and, for a limited offer, the
 * seats it holds.
 *
 * A limited offer is a request only while the registration holds its seat, or has been answered: a refused offer,
 * which gave its seat back, can still be answered "yes" again. A selection admitted or restored takes its seat
 * over the limit like every pool, so it is a request. The answer is the offer's own field: `isAnswered()` reads
 * `<key>_answer` from the values it is given. [UNAPPROVED N101, N356]
 */
final class OfferRequests implements RequestSource
{
    public const PREFIX = 'offer:';

    public function __construct(
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly Seats $seats = new Seats(),
    ) {
    }

    public function requestsOf(RegistrationKey $registration, array $values): array
    {
        $requests = [];
        $held = null;
        foreach ($this->offers->forEvent($registration->eventId) as $offer) {
            if (!$offer->needsRequest || ($values[$offer->key] ?? '') !== '1') {
                continue;
            }
            if ($offer->limit !== null && !self::answered($values, $offer->key)) {
                $held ??= $this->seats->poolsOf($registration);
                if (!in_array((new OfferSeatPool($registration->eventId, $offer->key))->key(), $held, true)) {
                    continue;
                }
            }
            $requests[] = new Request(
                self::PREFIX . $offer->key,
                RequestKind::Offer,
                $offer->label,
                [$offer->key],
                $offer->key . SetSupportOffers::ANSWER_SUFFIX,
            );
        }

        return $requests;
    }

    public function isAnswered(Request $request, array $values): bool
    {
        return self::answered($values, self::keyOf($request));
    }

    public function seatPool(RegistrationKey $registration, Request $request): ?SeatPool
    {
        $key = self::keyOf($request);
        $offer = $this->offers->find(['eventId' => $registration->eventId, 'key' => $key]);

        return $offer === null ? null : new OfferSeatPool($registration->eventId, $key, $this->offers);
    }

    public function writeAnswer(
        RegistrationKey $registration,
        Request $request,
        Answer $answer,
        Context $context,
    ): void {
        $this->registrations->writeValue(
            $registration,
            self::keyOf($request) . SetSupportOffers::ANSWER_SUFFIX,
            $answer->approved ? 'approved' : 'refused',
            $context,
            SetSupportOffers::DECLARER
        );
    }

    /** @param array<string, string> $values */
    private static function answered(array $values, string $key): bool
    {
        return in_array($values[$key . SetSupportOffers::ANSWER_SUFFIX] ?? '', ['approved', 'refused'], true);
    }

    private static function keyOf(Request $request): string
    {
        return substr($request->id, strlen(self::PREFIX));
    }
}
