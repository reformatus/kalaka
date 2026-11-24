<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\PriceLadder\LadderInput;
use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\PriceLadder\TierSeatPool;
use Kalaka\PriceLadder\TierRecord;
use Kalaka\PriceLadder\TierRepository;
use Kalaka\Registrations\Api\Seats;

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
        private readonly Seats $seats = new Seats(),
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
     * The seats left in each limited tier's pool, by the form-condition variable that reads it
     * (`seats_left.price_tier.<tier key>`: the field `price_tier` and the tier's key as its option value). A tier
     * with no limit has no variable. A plain read, never below zero.
     *
     * @return array<string, int>
     */
    public function seatsLeft(int $eventId): array
    {
        $left = [];
        foreach ($this->tiers->forEvent($eventId) as $record) {
            if ($record->limit !== null) {
                $left['seats_left.' . PriceLadderComponent::FIELD . '.' . $record->key] = $this->seats->left(
                    new TierSeatPool($eventId, $record->key, $this->tiers)
                ) ?? 0;
            }
        }

        return $left;
    }

    /**
     * A proposed ladder, the whole list of tiers in ladder order (an empty list removes the ladder), each
     * `{key, label, amount}`: `amount` a decimal string in the event's currency.
     *
     * @param list<array<string, mixed>> $tiers
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the ladder is not valid; `kalaka/set-price-ladder` checks its schema again
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

        return new ProposedLadder($this->ladder->tiers($event, $tiers), $input);
    }
}
