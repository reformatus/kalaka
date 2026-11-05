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

    /** Whether the kind is stamped with a value date, a calendar day. */
    public function carriesValueDate(): bool
    {
        return $this === self::ManualPayment || $this === self::ManualRefund || $this === self::Transfer;
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
