<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/** The balance of each payer of a registration and the total: above zero is due, below zero is overpaid. */
final readonly class Balances
{
    /** @param array<string, Money> $byPayer the payers with lines, by payer ID */
    public function __construct(private array $byPayer)
    {
    }

    /** @return array<string, Money> */
    public function byPayer(): array
    {
        return $this->byPayer;
    }

    /** Zero in `$currency`, the event's, when the payer has no lines. */
    public function of(string $payer, Currency $currency): Money
    {
        return $this->byPayer[$payer] ?? Money::zero($currency);
    }

    /** The sum of all payers; zero in `$currency`, the event's, without lines. */
    public function total(Currency $currency): Money
    {
        $total = Money::zero($currency);
        foreach ($this->byPayer as $balance) {
            $total = $total->plus($balance);
        }

        return $total;
    }
}
