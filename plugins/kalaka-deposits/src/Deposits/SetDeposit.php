<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Saves an event's deposit amount, or turns it off with no amount: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\Deposits::propose()`), which then re-evaluates every open
 * registration. Saving alone touches no registration. Site-level, like core's fixed price: full access only.
 * Turning it off deletes the row only. [UNAPPROVED N830, N837]
 */
final class SetDeposit implements AdminUseCase
{
    public function __construct(
        private readonly DepositSettingsRepository $settings = new DepositSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly DepositInput $input = new DepositInput(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-deposit';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'amount' => ['type' => ['string', 'null'], 'minLength' => 1, 'pattern' => DepositInput::SCHEMA_PATTERN],
            ],
            'required' => ['event_id', 'amount'],
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
        $this->events->find($eventId) ?? throw new NotFound();
        $amount = $input['amount'] === null ? null : (string) $input['amount'];

        $stored = $this->settings->findForUpdate($eventId, $context);
        if ($amount === null) {
            if ($stored !== null) {
                $this->settings->delete($stored, $context);
            }

            return null;
        }
        $amount = $this->input->amount($amount);
        if ($stored === null) {
            $this->settings->upsert(new DepositSettings($eventId, $amount), $context);
        } else {
            $stored->amount = $amount;
            $this->settings->upsert($stored, $context);
        }

        return null;
    }
}
