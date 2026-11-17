<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Saves an event's lunch items, the whole list in order: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\Lunch::propose()`), which then re-evaluates every open
 * registration. Items left out are deleted; an empty list removes lunch. Saving alone touches no
 * registration. Site-level, like core's fixed price: full access only. The currency must be the event's.
 */
final class SetLunch implements AdminUseCase
{
    public function __construct(
        private readonly LunchItemRepository $items = new LunchItemRepository(),
        private readonly Events $events = new Events(),
        private readonly LunchInput $input = new LunchInput(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-lunch';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'items' => ['type' => 'array', 'maxItems' => 50, 'items' => LunchInput::ITEM_SCHEMA],
            ],
            'required' => ['event_id', 'currency', 'items'],
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
        $proposed = $this->input->items($event, is_array($input['items']) ? $input['items'] : []);

        $stored = [];
        foreach ($this->items->forEvent($eventId, $context) as $record) {
            $stored[$record->key] = $record;
        }
        foreach ($proposed as $position => $item) {
            $record = $stored[$item->key] ?? null;
            unset($stored[$item->key]);
            if ($record === null) {
                $record = new LunchItemRecord(
                    $eventId,
                    $item->key,
                    $position,
                    $item->label,
                    $item->amount,
                    $item->condition
                );
                $this->items->upsert($record, $context);
                continue;
            }
            $record->position = $position;
            $record->label = $item->label;
            $record->amount = $item->amount;
            $record->condition = $item->condition;
            $this->items->upsert($record, $context);
        }
        foreach ($stored as $record) {
            $this->items->delete($record, $context);
        }

        return null;
    }
}
