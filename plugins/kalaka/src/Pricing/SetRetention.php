<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\RetentionRules;

/**
 * Saves an event's retention rule (the ID of a registered `RetentionRule`; an unknown one is refused): a step of a
 * money settings apply (`Api\MoneySettings::apply()`), which then
 * re-evaluates every registration of the event, those that left included. Saving alone touches no registration.
 * Site-level: full access only, like the fixed price.
 */
final class SetRetention implements AdminUseCase
{
    public function __construct(
        private readonly RetentionRepository $rules = new RetentionRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-retention';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                // Any registered rule's ID; an unknown one is refused in `execute()`.
                'retention' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
            'required' => ['event_id', 'retention'],
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
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }
        $rule = (string) $input['retention'];
        if (RetentionRules::find($rule) === null) {
            throw new InvalidInput();
        }
        $record = $this->rules->findForUpdate($eventId, $context);
        if ($record === null) {
            $this->rules->upsert(new RetentionRecord($eventId, $rule), $context);

            return null;
        }
        if ($record->rule !== $rule) {
            $record->rule = $rule;
            $this->rules->upsert($record, $context);
        }

        return null;
    }
}
