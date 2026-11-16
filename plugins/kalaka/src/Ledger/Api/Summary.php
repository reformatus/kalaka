<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The money of one registration per payer, for display: each payer's totals and its lines that are not
 * payment facts, in ledger order. Rules never read it: they read payment facts (`Ledger::paymentFactsOf()`).
 */
final readonly class Summary
{
    /**
     * @param array<string, PayerSummary> $payers the payers with lines, by payer ID, in payer order
     * @param array<string, list<ChargeLine>> $lines by payer ID
     */
    public function __construct(private array $payers, private array $lines)
    {
    }

    /** @return array<string, PayerSummary> the payers with lines, by payer ID */
    public function payers(): array
    {
        return $this->payers;
    }

    /** The payer's totals; all zero in `$currency`, the event's, when it has no lines. */
    public function of(string $payer, Currency $currency): PayerSummary
    {
        $zero = Money::zero($currency);

        return $this->payers[$payer] ?? new PayerSummary($payer, $zero, $zero, $zero);
    }

    /** @return list<ChargeLine> the payer's lines that are not payment facts, in ledger order */
    public function chargeLinesOf(string $payer): array
    {
        return $this->lines[$payer] ?? [];
    }
}
