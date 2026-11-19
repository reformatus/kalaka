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

    /**
     * The methods a manual refund may use: money returned by transfer or cash, never by card terminal
     * (money.md, "Recording payments by hand").
     *
     * @return list<self>
     */
    public static function forRefund(): array
    {
        return [self::BankTransfer, self::Cash];
    }

    /** The Hungarian label, approved by the owner 2026-11-18. */
    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Átutalás',
            self::Cash => 'Készpénz',
            self::CardTerminal => 'Bankkártya',
        };
    }
}
