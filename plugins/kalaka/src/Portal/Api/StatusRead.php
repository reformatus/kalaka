<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\Api\Phase;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Ledger\Api\PayerSummary;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentProviders;
use Kalaka\Payments\Api\PaymentsInFlight;
use Kalaka\Portal\CardRefusalState;
use Kalaka\Portal\LineLabels;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\Confirmations;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationRead;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The read behind the participant blocks: one registration's status, phase, confirmation, money and
 * transfer details, composed from the module Apis (one statement each) and the clock. It writes nothing
 * and never evaluates.
 *
 * `money` is read for every status, the registration's own money table beside the totals of `payment`.
 *
 * Whether the registration is confirmed comes from Pricing's `Confirmations`. Whether a request awaits an answer
 * is computed (`RegistrationFacts::awaitsAnswer`), never read from a stored flag: it replaces the old payment
 * hold. [UNAPPROVED N12]
 */
final class StatusRead
{
    private readonly MoneyRead $money;

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly Events $events = new Events(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly LineLabels $labels = new LineLabels(),
        ?MoneyRead $money = null,
        private readonly PaymentProviders $providers = new PaymentProviders(),
        private readonly PaymentsInFlight $inFlight = new PaymentsInFlight(),
    ) {
        $this->money = $money ?? new MoneyRead(labels: $labels);
    }

    /** The active registration's view, or null when there is none (or it no longer exists). */
    public function forActive(): ?RegistrationStatusView
    {
        $key = ActiveRegistration::current();

        // The one-shot refusal of a card payment form post is shown by the page that follows it, never by a mail.
        return $key === null ? null : $this->read($key, CardRefusalState::code());
    }

    /** The registration's view, or null when it or its event does not exist. */
    public function of(RegistrationKey $key): ?RegistrationStatusView
    {
        return $this->read($key, null);
    }

    private function read(RegistrationKey $key, ?string $cardRefusal): ?RegistrationStatusView
    {
        $registration = $this->registrations->find($key);
        $event = $registration === null ? null : $this->events->find($key->eventId);
        if ($registration === null || $event === null) {
            return null;
        }
        $availability = self::availability($event);

        $confirmation = null;
        $payment = null;
        $transfer = null;
        $can = new RegistrationCan();
        $card = null;
        if ($registration->status === RegistrationStatus::Registered) {
            $now = Runner::current()->clock()->now();
            $decisions = $this->decisions->currentOf($key);
            $confirmed = Confirmations::confirmedIn($decisions);
            $confirmation = new ConfirmationView(
                $confirmed,
                $decisions[ConfirmationRule::KIND]->effectiveAt ?? null
            );
            $facts = $this->registrations->factsOf($key, $event);
            $awaits = $facts !== null && $facts->awaitsAnswer;
            $payment = $this->payment($registration, $event, $confirmed, $awaits, $now, $facts?->paymentDue());
            // One balance read per status read: the rule's input is the balance, never the display summary.
            $balance = $this->ledger->balancesOf($key)->of(Payer::PARTICIPANT, $event->currency);
            $transfer = $this->transfer($key, $event, $availability, $payment, $balance);
            [$can, $card] = $this->card($key, $event, $availability, $payment, $balance);
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
            $this->money->of($key, $event->currency),
            $can,
            $card,
            $cardRefusal,
        );
    }

    /**
     * What the registration can be paid now, by the one rule of the transfer block (`PayableNow`): null unless it
     * is registered. The same reads as `of()` makes for the transfer, and no more.
     */
    public function payableNow(RegistrationKey $key): ?Money
    {
        $registration = $this->registrations->find($key);
        $event = $registration === null ? null : $this->events->find($key->eventId);
        if ($registration === null || $event === null || $registration->status !== RegistrationStatus::Registered) {
            return null;
        }
        $facts = $this->registrations->factsOf($key, $event);
        $balance = $this->ledger->balancesOf($key)->of(Payer::PARTICIPANT, $event->currency);
        $availability = self::availability($event);

        return PayableNow::amount($availability, $facts !== null && $facts->awaitsAnswer, $balance);
    }

    /**
     * Card payment: whether it can be started (the one payable rule and a provider that accepts the currency) and
     * the latest request. A provider is an outside point: one that throws is reported and read as not there, never
     * breaking the page.
     *
     * @return array{RegistrationCan, ?CardPaymentView}
     */
    private function card(
        RegistrationKey $key,
        Event $event,
        Availability $availability,
        PaymentView $payment,
        Money $balance,
    ): array {
        $provider = $this->providers->active();
        if ($provider === null) {
            return [new RegistrationCan(), null];
        }
        try {
            $payable = PayableNow::amount($availability, $payment->awaitsAnswer, $balance) !== null;
            $lookup = PaymentProviderInput::forLookup($key, $event->currency);
            $latest = $provider->latestOf($lookup);
            // Not while a request is in flight (the start's own rule): a failed or pending one is never paid, so
            // the next start replaces it.
            $idle = !$this->inFlight->is($provider, $key, $latest);
            $can = new RegistrationCan($payable && $idle && $provider->accepts($lookup));

            return [$can, $latest === null ? null : new CardPaymentView($latest->state, $latest->since)];
        } catch (\Throwable $e) {
            Boundary::report('Card payment state of ' . $provider->id(), $e);

            return [new RegistrationCan(), null];
        }
    }

    private static function availability(Event $event): Availability
    {
        return match ($event->state()) {
            EventState::Published => Availability::Open,
            EventState::Archived => Availability::Archived,
            default => Availability::EventClosed,
        };
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
        bool $awaits,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $due,
    ): PaymentView {
        $summary = $this->ledger->summaryOf($registration->key);
        $participant = self::amounts($summary->of(Payer::PARTICIPANT, $event->currency));
        $others = [];
        foreach ($summary->payers() as $payer => $amounts) {
            if ($payer !== Payer::PARTICIPANT) {
                $others[$payer] = self::amounts($amounts);
            }
        }

        // Overdue only while something is still payable, the same rule as the deadline line (SB-3).
        $owes = $participant->balance->roundedUpToPayableUnit()->isPositive();
        $overdue = $owes && !$confirmed && !$awaits && $due !== null && $now >= $due;

        return new PaymentView($participant, $others, $awaits, $due, $overdue);
    }

    /** SB-4: registered, the event open, not awaiting an answer, a balance above zero after rounding, and bank details set. */
    private function transfer(
        RegistrationKey $key,
        Event $event,
        Availability $availability,
        PaymentView $payment,
        Money $balance,
    ): ?TransferDetails {
        $amount = PayableNow::amount($availability, $payment->awaitsAnswer, $balance);
        $beneficiary = trim((string) $event->transferBeneficiary);
        $account = trim((string) $event->transferAccount);
        if ($amount === null || $beneficiary === '' || $account === '') {
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
