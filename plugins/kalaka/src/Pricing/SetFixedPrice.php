<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Saves an event's fixed price: the first step of a money settings apply (`Api\MoneySettings::apply()`),
 * which then re-evaluates every open registration. Saving alone touches no registration; each one
 * converges on its next evaluation. Site-level: full access only. The currency must be the event's.
 */
final class SetFixedPrice implements AdminUseCase
{
    public function __construct(
        private readonly FixedPriceRepository $prices = new FixedPriceRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-fixed-price';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
            ],
            'required' => ['event_id', 'amount', 'currency'],
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
        $event = $this->events->find($eventId);
        if ($event === null) {
            throw new NotFound();
        }
        $currency = Currency::tryFrom((string) $input['currency']);
        if ($currency !== $event->currency) {
            throw new InvalidInput();
        }
        $amount = Money::of((string) $input['amount'], $currency);

        $record = $this->prices->findForUpdate($eventId, $context);
        if ($record === null) {
            $this->prices->upsert(new FixedPriceRecord($eventId, $amount), $context);

            return null;
        }
        if (!$record->amount->isEqualTo($amount)) {
            $record->amount = $amount;
            $this->prices->upsert($record, $context);
        }

        return null;
    }
}
