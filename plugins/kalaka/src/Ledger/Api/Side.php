<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * money.md, "Ledger": the side of the ledger a line kind is on. Every line kind, an extension's included,
 * declares one: owed (what the payer has to pay) or paid (money that moved).
 */
enum Side: string
{
    case Owed = 'owed';
    case Paid = 'paid';
}
