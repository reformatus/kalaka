<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Money\Money;

/** money.md, "Ledger": the kinds of ledger line. */
enum LineKind: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';
    case ManualPayment = 'manual_payment';
    case ManualRefund = 'manual_refund';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';

    /**
     * The Hungarian label of the kind, where the admin shows a line. Drafts for the lektor. [UNAPPROVED T9..T15]
     */
    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Díj',
            self::Payment => 'Online befizetés',
            self::Refund => 'Online visszatérítés',
            self::ManualPayment => 'Befizetés',
            self::ManualRefund => 'Visszatérítés',
            self::Transfer => 'Áthelyezett befizetés',
            self::Adjustment => 'Korrekció',
        };
    }

    /** The lines that record money moving: what confirmation and the materializer read. */
    public function isPaymentFact(): bool
    {
        return $this !== self::Charge && $this !== self::Adjustment;
    }

    /** Whether the kind is stamped with the paid time, a UTC instant. */
    public function carriesPaidTime(): bool
    {
        return $this === self::Payment || $this === self::Refund;
    }

    /** Whether the kind is stamped with a value date, entered as a day and stored as a UTC instant. */
    public function carriesValueDate(): bool
    {
        return $this === self::ManualPayment || $this === self::ManualRefund || $this === self::Transfer;
    }

    /** Whether the kind records how it was paid: the manual payment and refund. */
    public function carriesMethod(): bool
    {
        return $this === self::ManualPayment || $this === self::ManualRefund;
    }

    /** Whether the kind may have a note: a manual entry, a moved payment, an adjustment's reason. */
    public function mayHaveNote(): bool
    {
        return $this->carriesMethod() || $this === self::Transfer || $this === self::Adjustment;
    }

    /** The sign rule: payments below zero, refunds above zero, the others either; never exactly zero. */
    public function allows(Money $amount): bool
    {
        return match ($this) {
            self::Payment, self::ManualPayment => $amount->isNegative(),
            self::Refund, self::ManualRefund => $amount->isPositive(),
            default => $amount->isNegative() || $amount->isPositive(),
        };
    }
}
