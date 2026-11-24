<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Registrations\Api\Registrations;

/**
 * The rule that keeps a ladder save from under-counting a tier ([UNAPPROVED N2], as #237's `OfferChangeGuard`
 * does for offers): a limit cannot be given to a tier an open (`registered` or `waitlisted`) registration has
 * already chosen, because those registrations hold no seat and the pool would count only the seats taken from
 * then on. A lower limit and removing a limit are allowed. It runs in the preview and before any save of an
 * apply, and again inside the use case as a guard. A plain read: one `Registrations::openHolds()` per tier that
 * gains a limit.
 *
 * The race with a submit that chooses the tier while it is saved is accepted: an unlimited tier's row is not
 * locked by a submit, so the choice can slip in after this check.
 */
final class TierLimitGuard
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly TierRepository $tiers = new TierRepository(),
    ) {
    }

    /**
     * The same check against the event's stored tiers, read plainly: for the preview and for the apply before it
     * saves anything.
     *
     * @param list<Tier> $proposed
     * @throws RuleViolation when a tier that gains a limit is chosen on an open registration
     */
    public function checkAgainstStored(int $eventId, array $proposed): void
    {
        $stored = [];
        foreach ($this->tiers->forEvent($eventId) as $record) {
            $stored[$record->key] = $record;
        }
        $this->check($eventId, $stored, $proposed);
    }

    /**
     * @param array<string, TierRecord> $stored by key
     * @param list<Tier> $proposed
     * @throws RuleViolation when a tier that gains a limit is chosen on an open registration
     */
    public function check(int $eventId, array $stored, array $proposed): void
    {
        foreach ($proposed as $tier) {
            $gains = $tier->limit !== null && ($stored[$tier->key]->limit ?? null) === null;
            if ($gains && $this->registrations->openHolds($eventId, PriceLadderComponent::FIELD, $tier->key)) {
                throw new RuleViolation();
            }
        }
    }
}
