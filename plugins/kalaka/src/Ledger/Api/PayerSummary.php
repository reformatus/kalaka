<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One payer's side of a registration's ledger (money.md, "Ledger"): what it was charged (the sum of its
 * lines that are not payment facts: charges, adjustments, feature lines), what it paid (minus the sum of
 * its payment-fact lines: payments less refunds, transfers either way) and the balance, charged − paid:
 * above zero is due, below zero is overpaid.
 */
final readonly class PayerSummary
{
    public function __construct(
        public string $payer,
        public Money $charged,
        public Money $paid,
        public Money $balance,
    ) {
    }
}
