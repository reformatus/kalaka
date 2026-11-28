<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * The money of one `registered` registration, the participant payer first (SB-6): the totals, the other payers,
 * whether a request awaits an answer and the due time. The itemised table is `ParticipantMoney`, read beside it.
 *
 * - `otherPayers`: the other payers with lines (e.g. the congregation), by payer ID.
 * - `awaitsAnswer`: a request awaits an answer (computed, never stored): no due date, no transfer block
 *   (S32, SB-4).
 * - `paymentDue`: the end of the due day in the site time zone, as a UTC instant; null while it awaits an answer or
 *   while the event has no payment-due N.
 * - `overdue`: SB-3, `registered ∧ ¬confirmed ∧ ¬awaitsAnswer ∧ now ≥ paymentDue`.
 */
final readonly class PaymentView
{
    /**
     * @param array<string, PayerAmounts> $otherPayers
     */
    public function __construct(
        public PayerAmounts $participant,
        public array $otherPayers,
        public bool $awaitsAnswer,
        public ?\DateTimeImmutable $paymentDue,
        public bool $overdue,
    ) {
    }
}
