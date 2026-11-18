<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * money.md, "Ledger": how a manual payment or refund was made. A fixed list in core, stored on the line
 * (owner ruling 2026-11-18). [UNAPPROVED Shapes]
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case CardTerminal = 'card_terminal';

    /** The Hungarian label, a draft for the lektor. [UNAPPROVED T2, T3, T4] */
    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Átutalás',
            self::Cash => 'Készpénz',
            self::CardTerminal => 'Bankkártya',
        };
    }
}
