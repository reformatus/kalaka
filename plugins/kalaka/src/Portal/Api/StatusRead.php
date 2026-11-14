<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\Api\Phase;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\PayerSummary;
use Kalaka\Portal\LineLabels;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationRead;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The read behind the participant blocks: one registration's status, phase, confirmation, money and
 * transfer details, composed from the module Apis (one statement each) and the clock. It writes nothing
 * and never evaluates.
 *
 * Two decision kinds are read by name, before the slices that write them: `confirmation` (S20) with the
 * state `confirmed`, and `payment_hold` (S32) with the state `held`.
 */
final class StatusRead
{
    /** The core confirmation decision (money.md, "Confirmation"). */
    public const CONFIRMATION = 'confirmation';

    /** A pending decision holds payment (money.md, "Core money scope"). */
    public const PAYMENT_HOLD = 'payment_hold';

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly Events $events = new Events(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly LineLabels $labels = new LineLabels(),
    ) {
    }

    /** The active registration's view, or null when there is none (or it no longer exists). */
    public function forActive(): ?RegistrationStatusView
    {
        $key = ActiveRegistration::current();

        return $key === null ? null : $this->of($key);
    }

    /** The registration's view, or null when it or its event does not exist. */
    public function of(RegistrationKey $key): ?RegistrationStatusView
    {
        $registration = $this->registrations->find($key);
        $event = $registration === null ? null : $this->events->find($key->eventId);
        if ($registration === null || $event === null) {
            return null;
        }
        $availability = match ($event->state()) {
            EventState::Published => Availability::Open,
            EventState::Archived => Availability::Archived,
            default => Availability::EventClosed,
        };

        $confirmation = null;
        $payment = null;
        $transfer = null;
        if ($registration->status === RegistrationStatus::Registered) {
            $now = Runner::current()->clock()->now();
            $decisions = $this->decisions->currentOf($key);
            $confirmed = isset($decisions[self::CONFIRMATION])
                && $decisions[self::CONFIRMATION]->state === 'confirmed';
            $confirmation = new ConfirmationView(
                $confirmed,
                $decisions[self::CONFIRMATION]->effectiveAt ?? null
            );
            $held = isset($decisions[self::PAYMENT_HOLD]) && $decisions[self::PAYMENT_HOLD]->state === 'held';
            $payment = $this->payment($registration, $event, $confirmed, $held, $now);
            $transfer = $this->transfer($key, $event, $availability, $payment);
        }

        return new RegistrationStatusView(
            $key,
            $availability,
            $event->name,
            $registration->status,
            self::phase($registration->status, $confirmation !== null && $confirmation->confirmed),
            $confirmation,
            $payment,
            $transfer,
        );
    }

    private static function phase(RegistrationStatus $status, bool $confirmed): Phase
    {
        return match ($status) {
            RegistrationStatus::Waitlisted => Phase::Waitlisted,
            RegistrationStatus::Withdrawn => Phase::Withdrawn,
            RegistrationStatus::Removed => Phase::Removed,
            RegistrationStatus::Registered => $confirmed ? Phase::Confirmed : Phase::AwaitingPayment,
        };
    }

    private function payment(
        RegistrationRead $registration,
        Event $event,
        bool $confirmed,
        bool $held,
        \DateTimeImmutable $now,
    ): PaymentView {
        $summary = $this->ledger->summaryOf($registration->key);
        $participant = self::amounts($summary->of(Payer::PARTICIPANT, $event->currency));
        $others = [];
        foreach ($summary->payers() as $payer => $amounts) {
            if ($payer !== Payer::PARTICIPANT) {
                $others[$payer] = self::amounts($amounts);
            }
        }

        // SB-13: itemised only when every line has a label; otherwise the totals alone.
        $lines = [];
        foreach ($summary->chargeLinesOf(Payer::PARTICIPANT) as $line) {
            $label = $this->labels->labelOf($line);
            if ($label === null) {
                $lines = [];
                break;
            }
            $lines[] = new PaymentLine($label, $line->amount->isNegative() ? -1 : 1, $line->amount->isNegative()
                ? $line->amount->negated() : $line->amount);
        }

        $due = $held ? null : $event->paymentDue($registration->registeredAt);
        $overdue = !$confirmed && !$held && $due !== null && $now >= $due;

        return new PaymentView($participant, $lines, $others, $held, $due, $overdue);
    }

    /** SB-4: registered, the event open, not held, a balance above zero after rounding, and bank details set. */
    private function transfer(
        RegistrationKey $key,
        Event $event,
        Availability $availability,
        PaymentView $payment,
    ): ?TransferDetails {
        $amount = $payment->participant->balance->roundedUpToPayableUnit();
        $beneficiary = trim((string) $event->transferBeneficiary);
        $account = trim((string) $event->transferAccount);
        if (
            $availability !== Availability::Open || $payment->held || !$amount->isPositive()
            || $beneficiary === '' || $account === ''
        ) {
            return null;
        }
        $prefix = trim((string) $event->transferReferencePrefix);

        return new TransferDetails(
            $beneficiary,
            $account,
            $amount,
            $prefix === '' ? $key->code : $prefix . ' ' . $key->code
        );
    }

    private static function amounts(PayerSummary $summary): PayerAmounts
    {
        return new PayerAmounts($summary->payer, $summary->charged, $summary->paid, $summary->balance);
    }
}
