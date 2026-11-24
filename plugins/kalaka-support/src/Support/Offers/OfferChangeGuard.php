<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Registrations\Api\Registrations;
use Kalaka\Support\Api\Offer;
use Kalaka\Support\Api\OfferSelected;

/**
 * The rule that keeps a settings save from taking something from somebody (Q-B0-6, [UNAPPROVED N99]): an offer
 * an open (`registered` or `waitlisted`) registration has selected cannot be removed, given a limit it had not,
 * or have its "must be requested" flag flipped (a seat, a line, or a refusal would be taken or overridden).
 * A lower limit is allowed: the pool only stands over. It runs in the preview and before any save of an apply,
 * and again inside the use case as a guard. A plain read: the selections are one statement per watched offer.
 *
 * The race with a submit that selects an offer while it is saved is accepted: an unlimited offer's row is not
 * locked by a submit, so the selection can slip in after this check.
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
            $changes = $next === null
                || ($record->limit === null && $next->limit !== null)
                || $record->needsRequest !== $next->needsRequest;
            if ($changes && $this->registrations->openHolds($eventId, $key, '1')) {
                throw new OfferSelected();
            }
        }
    }
}
