<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Extras\Lunch\LunchInput;
use Kalaka\Extras\Lunch\LunchItemRecord;
use Kalaka\Extras\Lunch\LunchItemRepository;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;

/**
 * Lunch for every other module and the settings screen (S72): an event's lunch items, and proposed items to
 * hand to `Pricing\Api\MoneySettings` (preview → confirm → apply; money.md, "Money settings apply"). Plain
 * reads, outside any use case.
 */
final class Lunch
{
    public function __construct(
        private readonly LunchItemRepository $items = new LunchItemRepository(),
        private readonly Events $events = new Events(),
        private readonly LunchInput $input = new LunchInput(),
    ) {
    }

    /**
     * The event's lunch items in order; none when it has no lunch.
     *
     * @return list<LunchItem>
     */
    public function items(int $eventId): array
    {
        return array_map(
            static fn (LunchItemRecord $record): LunchItem => $record->item(),
            $this->items->forEvent($eventId)
        );
    }

    /**
     * Proposed lunch items, the whole list in order (an empty list removes lunch), each
     * `{key, label, amount, condition?}`: `amount` a decimal string in the event's currency, `condition` in
     * the one condition format.
     *
     * @param list<array<string, mixed>> $items
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the items are not valid; `kalaka/set-lunch` checks its schema again
     */
    public function propose(int $eventId, array $items): ProposedLunch
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        foreach ($items as $item) {
            if (rest_validate_value_from_schema($item, LunchInput::ITEM_SCHEMA) !== true) {
                throw new InvalidInput();
            }
        }
        $input = ['event_id' => $eventId, 'currency' => $event->currency->value, 'items' => $items];

        return new ProposedLunch($this->input->items($event, $items), $input);
    }
}
