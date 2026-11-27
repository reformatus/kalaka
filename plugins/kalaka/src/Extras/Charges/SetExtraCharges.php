<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Saves an event's extra charges, the whole list in order: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\Extras::propose()`), which then re-evaluates every open
 * registration. Charges left out are deleted; an empty list removes them. Charging alone touches no
 * registration. Site-level, like core's fixed price: full access only. The currency must be the event's, and
 * a number field with a charge's key must take whole numbers.
 */
final class SetExtraCharges implements AdminUseCase
{
    public function __construct(
        private readonly ChargeRepository $charges = new ChargeRepository(),
        private readonly Events $events = new Events(),
        private readonly ChargesInput $input = new ChargesInput(),
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
        $event = $this->events->find($eventId) ?? throw new NotFound();
        if (Currency::tryFrom((string) $input['currency']) !== $event->currency) {
            throw new InvalidInput();
        }
        $proposed = $this->input->charges($event, is_array($input['charges']) ? $input['charges'] : []);

        $stored = [];
        foreach ($this->charges->forEvent($eventId, $context) as $record) {
            $stored[$record->key] = $record;
        }
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

        return null;
    }
}
