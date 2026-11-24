<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\Retention;

/**
 * Saves an event's retention rule: a step of a money settings apply (`Api\MoneySettings::apply()`), which then
 * re-evaluates every registration of the event, those that left included. Saving alone touches no registration.
 * Site-level: full access only, like the fixed price. [UNAPPROVED N526]
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
                'retention' => ['type' => 'string', 'enum' => array_map(
                    static fn (Retention $rule): string => $rule->value,
                    Retention::cases()
                )],
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
        $rule = Retention::from((string) $input['retention']);
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
