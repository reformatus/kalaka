<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Registrations\Api\Registrations;

/**
 * The rules that keep a ladder save from going wrong, checked in the preview and before any save of an apply, and
 * again inside the use case as a guard, so a refusal comes from here and never from a failure afterwards:
 * - a tier an open (`registered` or `waitlisted`) registration has chosen is not removed ([UNAPPROVED N129], as
 *   #237's `OfferChangeGuard` does for a selected offer): the registration would have no price to owe, and the
 *   re-price would fail only after the save. A removed tier no open registration chose is removed as before, and
 *   its option is retired. A plain read: one `Registrations::refuseRemovingChosen()` per removed tier, which
 *   refuses with `ItemChosen` (`kalaka_item_chosen`).
 * - `price_tier` is free for the ladder to declare (`ParticipantFields::checkDeclarable()`, the one check the
 *   declaration makes too) whenever the event has a ladder, stored or proposed ([UNAPPROVED N130]).
 *
 * Every chosen tier holds a seat row, limited or not, so a limit may be added or changed at any time. The race
 * with a submit that chooses the tier while it is removed is accepted: the submit's pool lock finds the row gone.
 */
final class LadderSaveGuard
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly TierRepository $tiers = new TierRepository(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
    ) {
    }

    /**
     * The same check against the event's stored tiers, read plainly: for the preview and for the apply before it
     * saves anything.
     *
     * @param list<Tier> $proposed
     * @throws \Kalaka\Registrations\Api\ItemChosen when a removed tier is chosen on an open registration
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
     * @throws RuleViolation when a removed tier is chosen on an open registration, or `price_tier` is not free
     */
    public function check(int $eventId, array $stored, array $proposed): void
    {
        if ($proposed !== [] || $stored !== []) {
            $this->fields->checkDeclarable(
                $eventId,
                PriceLadderComponent::FIELD,
                FieldKind::CHOICE,
                PriceLadderComponent::DECLARER
            );
        }
        foreach ($proposed as $tier) {
            unset($stored[$tier->key]);
        }
        foreach (array_keys($stored) as $key) {
            $this->registrations->refuseRemovingChosen($eventId, PriceLadderComponent::FIELD, (string) $key);
        }
    }
}
