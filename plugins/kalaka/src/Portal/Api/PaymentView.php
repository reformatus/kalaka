<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * The money of one `registered` registration, the participant payer first (SB-6).
 *
 * - `lines`: the participant payer's itemised lines (SB-13), empty unless every one of them has a label;
 *   the signed lines then sum to `participant->charged`.
 * - `otherPayers`: the other payers with lines (e.g. the congregation), by payer ID.
 * - `held`: a pending decision holds payment (S32, SB-4).
 * - `paymentDue`: the end of the due day in the site time zone, as a UTC instant; null while held or
 *   while the event has no payment-due N.
 * - `overdue`: SB-3, `registered ∧ ¬confirmed ∧ ¬held ∧ now ≥ paymentDue`.
 */
final readonly class PaymentView
{
    /**
     * @param list<PaymentLine> $lines
     * @param array<string, PayerAmounts> $otherPayers
     */
    public function __construct(
        public PayerAmounts $participant,
        public array $lines,
        public array $otherPayers,
        public bool $held,
        public ?\DateTimeImmutable $paymentDue,
        public bool $overdue,
    ) {
    }
}
