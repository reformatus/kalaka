<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Registrations\Api\Registrations;
use Kalaka\Support\Api\Offer;
use Kalaka\Support\Api\OfferSelected;

/**
 * The rule that keeps a settings save from taking something from somebody (Q-B0-6, [UNAPPROVED N99]): an offer
 * an open (`registered` or `waitlisted`) registration has selected cannot be removed or have its "must be
 * requested" flag flipped (a line or a refusal would be taken or overridden), judged by the one shared read
 * `Registrations::openHolds()`. A limit may be added, lowered or raised at any time (every chooser holds a seat
 * row, so the count is right): a lower limit only makes the pool stand over. It runs in the preview and before
 * any save of an apply, and again inside the use case as a guard. A plain read: the selections are one statement
 * per watched offer.
 *
 * A submit that selects an offer locks the offer's row (every chosen offer is claimed, limited or not), so the
 * guard inside the use case, which holds the rows, sees every selection that was committed before it.
 */
final class OfferChangeGuard
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly OfferRepository $offers = new OfferRepository(),
    ) {
    }

    /**
     * The same check against the event's stored offers, read plainly: for the preview and for the apply before it
     * saves anything.
     *
     * @param list<Offer> $proposed
     * @throws OfferSelected when a watched offer is selected on an open registration
     */
    public function checkAgainstStored(int $eventId, array $proposed): void
    {
        $stored = [];
        foreach ($this->offers->forEvent($eventId) as $record) {
            $stored[$record->key] = $record;
        }
        $this->check($eventId, $stored, $proposed);
    }

    /**
     * @param array<string, OfferRecord> $stored by key
     * @param list<Offer> $proposed
     * @throws OfferSelected when a watched offer is selected on an open registration
     */
    public function check(int $eventId, array $stored, array $proposed): void
    {
        $kept = [];
        foreach ($proposed as $offer) {
            $kept[$offer->key] = $offer;
        }
        foreach ($stored as $key => $record) {
            $next = $kept[$key] ?? null;
            $changes = $next === null || $record->needsRequest !== $next->needsRequest;
            if ($changes && $this->registrations->openHolds($eventId, $key, '1')) {
                throw new OfferSelected();
            }
        }
    }
}
