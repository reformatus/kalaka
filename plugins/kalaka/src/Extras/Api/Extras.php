<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Extras\Charges\ChargeRecord;
use Kalaka\Extras\Charges\ChargeRepository;
use Kalaka\Extras\Charges\ChargesInput;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;

/**
 * Extra charges for every other module and the settings screen (S72): an event's charges, and proposed
 * ones to hand to `Pricing\Api\MoneySettings` (preview → confirm → apply; money.md, "Money settings
 * apply"). Plain reads, outside any use case.
 */
final class Extras
{
    public function __construct(
        private readonly ChargeRepository $charges = new ChargeRepository(),
        private readonly Events $events = new Events(),
        private readonly ChargesInput $input = new ChargesInput(),
    ) {
    }

    /**
     * The event's charges in order; none when it has none.
     *
     * @return list<Charge>
     */
    public function charges(int $eventId): array
    {
        return array_map(
            static fn (ChargeRecord $record): Charge => $record->charge(),
            $this->charges->forEvent($eventId)
        );
    }

    /**
     * Proposed charges, the whole list in order (an empty list removes them), each
     * `{key, label, amount}`: `amount` a decimal string in the event's currency; the participant field with
     * the same key calls it.
     *
     * @param list<array<string, mixed>> $charges
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the charges are not valid; `kalaka/set-extra-charges` checks its schema again
     */
    public function propose(int $eventId, array $charges): ProposedExtras
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        foreach ($charges as $charge) {
            if (rest_validate_value_from_schema($charge, ChargesInput::ITEM_SCHEMA) !== true) {
                throw new InvalidInput();
            }
        }
        $input = ['event_id' => $eventId, 'currency' => $event->currency->value, 'charges' => $charges];

        return new ProposedExtras($this->input->charges($event, $charges), $input);
    }
}
