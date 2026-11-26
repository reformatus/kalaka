<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Extras\Charges\ChargeField;
use Kalaka\Extras\Charges\ChargeRecord;
use Kalaka\Extras\Charges\ChargeRepository;
use Kalaka\Extras\Charges\ChargesInput;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantFields;

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
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly ChargeField $field = new ChargeField(),
    ) {
    }

    /**
     * The event's charges in order; none when it has none. A charge's type is its declared field's: a number for
     * a number field whatever its source, a consent otherwise.
     *
     * @return list<Charge>
     */
    public function charges(int $eventId): array
    {
        $fields = [];
        foreach ($this->fields->of($eventId, true) as $field) {
            $fields[$field->key] = $field;
        }

        return array_map(
            static fn (ChargeRecord $record): Charge => new Charge(
                $record->key,
                $record->label,
                $record->amount,
                ChargeField::typeOf($fields, $record->key),
            ),
            $this->charges->forEvent($eventId)
        );
    }

    /**
     * Proposed charges, the whole list in order (an empty list removes them), each
     * `{key, label, amount, type?}`: `amount` a decimal string in the event's currency, `type` `consent` (the
     * default) or `number`; the charge declares the participant field with its key and type when it is saved.
     *
     * @param list<array<string, mixed>> $charges
     * @throws NotFound when there is no such event
     * @throws RuleViolation when a field another source defined holds a charge's key, or (`FieldDecimalsHeld`)
     *     when a form holds a number charge's key with decimals
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
        // The forms first (the whole save is refused, naming them), before the record-based checks below.
        foreach ($charges as $charge) {
            if (($charge['type'] ?? Charge::CONSENT) === Charge::NUMBER) {
                $this->field->checkForms($eventId, (string) $charge['key']);
            }
        }
        $input = ['event_id' => $eventId, 'currency' => $event->currency->value, 'charges' => $charges];

        return new ProposedExtras($this->input->charges($event, $charges), $input);
    }
}
