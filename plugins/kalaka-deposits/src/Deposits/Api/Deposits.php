<?php

declare(strict_types=1);

namespace Kalaka\Deposits\Api;

use Kalaka\Deposits\DepositInput;
use Kalaka\Deposits\DepositSettingsRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;

/**
 * The event's deposit for every other module and the money settings (money.md, "Confirmation" and
 * "Withdrawal, removal and substitution"): the amount as decimal text, and proposed settings to hand to
 * `Pricing\Api\MoneySettings` (preview → confirm → apply; money.md, "Money settings apply"). Plain reads,
 * outside any use case.
 */
final class Deposits
{
    public function __construct(
        private readonly DepositSettingsRepository $settings = new DepositSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly DepositInput $input = new DepositInput(),
    ) {
    }

    /** The event's deposit as decimal text in the event's currency, or null when it has none. */
    public function amount(int $eventId): ?string
    {
        return $this->settings->find($eventId)?->amount;
    }

    /**
     * Proposed deposit settings: the amount as decimal text (greater than zero, at most two decimals), or null
     * to turn the event's deposit off.
     *
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the amount is not valid; `kalaka/set-deposit` checks its schema again
     */
    public function propose(int $eventId, ?string $amount): ProposedDeposit
    {
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }
        if ($amount !== null) {
            $amount = $this->input->amount($amount);
        }

        return new ProposedDeposit($amount, ['event_id' => $eventId, 'amount' => $amount]);
    }
}
