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

    /**
     * The Hungarian label of the kind, where the admin shows a line. Drafts for the lektor. [UNAPPROVED T9..T13]
     */
    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Díj',
            self::Payment => 'Online befizetés',
            self::Refund => 'Online visszafizetés',
            self::ManualPayment => 'Kézi befizetés',
            self::ManualRefund => 'Kézi visszafizetés',
        };
    }

    /** The side of the ledger the kind is on: a charge is owed, every payment fact is paid (money.md, "Ledger"). */
    public function side(): Side
    {
        return $this === self::Charge ? Side::Owed : Side::Paid;
    }

    /** The lines that record money moving: what confirmation and the materializer read. */
    public function isPaymentFact(): bool
    {
        return $this->side() === Side::Paid;
    }

    /** Whether the kind is stamped with the paid time, a UTC instant. */
    public function carriesPaidTime(): bool
    {
        return $this === self::Payment || $this === self::Refund;
    }

    /** Whether the kind is stamped with a value date, entered as a day and stored as a UTC instant. */
    public function carriesValueDate(): bool
    {
        return $this === self::ManualPayment || $this === self::ManualRefund;
    }

    /** Whether the kind records how it was paid: the manual payment and refund. */
    public function carriesMethod(): bool
    {
        return $this === self::ManualPayment || $this === self::ManualRefund;
    }

    /** Whether the kind may have a note: a manual entry (a bank reference, a payer note or a correction's reason). */
    public function mayHaveNote(): bool
    {
        return $this->carriesMethod();
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
