<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\Api\Events;
use Kalaka\Extras\Api\Charge;
use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Saves an event's extra charges, the whole list in order: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\Extras::propose()`), which then re-evaluates every open
 * registration. Charges left out are deleted; an empty list removes them. Charging alone touches no
 * registration. Site-level, like core's fixed price: full access only. The currency must be the event's. Each
 * charge declares its participant field (key = the charge's key, its type, its label as the title), and the
 * donation's number field `adomany` is declared too, so a form field joins them by key and never creates them;
 * a `form` field of the same type under a key is adopted, and a key a staff or system field or a field of another
 * type holds refuses the save, whole. A number field multiplying a charge must take whole numbers: the declared
 * field fixes its decimals at 0.
 */
final class SetExtraCharges implements AdminUseCase
{
    public function __construct(
        private readonly ChargeRepository $charges = new ChargeRepository(),
        private readonly Events $events = new Events(),
        private readonly ChargesInput $input = new ChargesInput(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly ChargeRemovalGuard $guard = new ChargeRemovalGuard(),
        private readonly EventCapacity $capacity = new EventCapacity(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-extra-charges';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'charges' => ['type' => 'array', 'maxItems' => 50, 'items' => ChargesInput::ITEM_SCHEMA],
            ],
            'required' => ['event_id', 'currency', 'charges'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        // The event row first, as a registration submit takes it: a submit that chooses a charge has committed or
        // waits, so the guard below reads what the save may not take out (D3).
        $this->capacity->lockForRegistration($context, $eventId);
        $event = $this->events->find($eventId) ?? throw new NotFound();
        if (Currency::tryFrom((string) $input['currency']) !== $event->currency) {
            throw new InvalidInput();
        }
        $proposed = $this->input->charges($event, is_array($input['charges']) ? $input['charges'] : []);

        $stored = [];
        foreach ($this->charges->forEvent($eventId, $context) as $record) {
            $stored[$record->key] = $record;
        }
        // A charge an open registration chose is not taken out (D3): refused before anything is written.
        $this->guard->check(
            $eventId,
            array_keys($stored),
            array_map(static fn (Charge $charge): string => $charge->key, $proposed)
        );
        foreach ($proposed as $position => $charge) {
            $record = $stored[$charge->key] ?? null;
            unset($stored[$charge->key]);
            if ($record === null) {
                $record = new ChargeRecord(
                    $eventId,
                    $charge->key,
                    $position,
                    $charge->label,
                    $charge->amount,
                );
                $this->charges->upsert($record, $context);
                continue;
            }
            $record->position = $position;
            $record->label = $charge->label;
            $record->amount = $charge->amount;
            $this->charges->upsert($record, $context);
        }
        foreach ($stored as $record) {
            $this->charges->delete($record, $context);
        }
        // The code defines its fields (events-and-forms.md, "Fields"); a form field joins one by its key. A charge
        // taken out (one no open registration chose: the guard above refuses the others) leaves its field declared,
        // as for every kind of item. [UNAPPROVED N3]
        foreach ($proposed as $charge) {
            $this->fields->declareExtensionField(
                $context,
                $eventId,
                $charge->key,
                ChargeField::block($charge->type),
                $charge->label,
                null,
                true,
                ChargeField::DECLARER,
                $charge->type === Charge::NUMBER ? 0 : null,
                true,
            );
        }
        // The donation's number field is declared whenever the extras are saved, with or without charges.
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            DonationComponent::KEY,
            FieldKind::NUMBER,
            DonationComponent::FIELD_TITLE,
            null,
            true,
            ChargeField::DECLARER,
            null,
            true,
        );

        return null;
    }
}
