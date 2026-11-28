<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * One payer's side of a registration's ledger for display, from one read: its owed lines in ledger order, its
 * money movements (the paid-side lines, notes included) by value day then ledger order, and the totals summed
 * from those same lines. Rules never read it.
 */
final readonly class PayerStatement
{
    /**
     * @param list<LedgerEntry> $owed
     * @param list<LedgerEntry> $movements
     */
    public function __construct(
        public string $payer,
        public array $owed,
        public array $movements,
        public PayerSummary $totals,
    ) {
    }
}
