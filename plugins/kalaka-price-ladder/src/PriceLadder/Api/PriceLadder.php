<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\PriceLadder\LadderInput;
use Kalaka\PriceLadder\LadderSaveGuard;
use Kalaka\PriceLadder\TierRecord;
use Kalaka\PriceLadder\TierRepository;

/**
 * The price ladder for every other module and the settings screen (S72): an event's tiers, and a proposed
 * ladder to hand to `Pricing\Api\MoneySettings` (preview → confirm → apply; money.md, "Money settings
 * apply"). Plain reads, outside any use case.
 */
final class PriceLadder
{
    public function __construct(
        private readonly TierRepository $tiers = new TierRepository(),
        private readonly Events $events = new Events(),
        private readonly LadderInput $ladder = new LadderInput(),
        private readonly LadderSaveGuard $guard = new LadderSaveGuard(),
    ) {
    }

    /**
     * The event's tiers in ladder order; none when it has no ladder.
     *
     * @return list<Tier>
     */
    public function tiers(int $eventId): array
    {
        return array_map(static fn (TierRecord $record): Tier => $record->tier(), $this->tiers->forEvent($eventId));
    }

    /**
     * A proposed ladder, the whole list of tiers in ladder order (an empty list removes the ladder), each
     * `{key, label, amount}` and an optional `limit` (an integer of 0 or more: the people the tier takes): `amount` a
     * decimal string in the event's currency.
     *
     * @param list<array<string, mixed>> $tiers
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the ladder is not valid; `kalaka/set-price-ladder` checks its schema again
     * @throws RuleViolation when a tier an open registration has chosen would be removed, or when `price_tier` is
     *     a field another declarer holds (`LadderSaveGuard`): the preview and the apply refuse before anything is
     *     saved
     */
    public function propose(int $eventId, array $tiers): ProposedLadder
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        $input = ['event_id' => $eventId, 'currency' => $event->currency->value, 'tiers' => $tiers];
        foreach ($tiers as $tier) {
            if (rest_validate_value_from_schema($tier, LadderInput::TIER_SCHEMA) !== true) {
                throw new InvalidInput();
            }
        }

        $proposed = $this->ladder->tiers($event, $tiers);
        $this->guard->checkAgainstStored($eventId, $proposed);

        return new ProposedLadder($proposed, $input);
    }
}
