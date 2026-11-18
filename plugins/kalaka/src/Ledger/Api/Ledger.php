<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\BalanceQuery;
use Kalaka\Ledger\ChargeTotalQuery;
use Kalaka\Ledger\LedgerLineRepository;
use Kalaka\Ledger\LineListQuery;
use Kalaka\Ledger\PaymentNote;
use Kalaka\Ledger\PaymentNoteRepository;
use Kalaka\Ledger\PaymentFactQuery;
use Kalaka\Ledger\SummaryQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/** The ledger's contract: the only way to write a line, and the plain reads. */
final class Ledger
{
    public function __construct(
        private readonly LedgerLineRepository $lines = new LedgerLineRepository(),
        private readonly BalanceQuery $balances = new BalanceQuery(),
        private readonly PaymentFactQuery $facts = new PaymentFactQuery(),
        private readonly SummaryQuery $summaries = new SummaryQuery(),
        private readonly ChargeTotalQuery $charges = new ChargeTotalQuery(),
        private readonly PaymentNoteRepository $notes = new PaymentNoteRepository(),
        private readonly LineListQuery $listing = new LineListQuery(),
    ) {
    }

    /**
     * Appends the line, or returns the stored one when the registration already holds the same line
     * under the key. A value date, a day, is stored as 00:00 of it in the site time zone at the moment
     * of recording. The run must hold the registration's row lock (`Context::holdsRegistration()`). The line's
     * free text, when it has one, is written to its own side record (`kalaka_payment_notes`), in the same run.
     *
     * @throws SystemFailure when the run does not hold the registration, the line breaks a rule, the key
     *                       holds a different line, or the insert fails
     */
    public function record(NewLine $line, Context $context): LedgerLine
    {
        if (!$context->holdsRegistration($line->registration)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
            throw $error;
        }
        $this->check($line);
        $valueDate = $line->valueDate === null ? null : Instant::startOfDay($line->valueDate);

        $stored = $this->lines->findByKey($line->registration, $line->idempotencyKey);
        if ($stored !== null) {
            if (!self::isSame($stored, $line, $valueDate) || $this->noteOf($stored) !== self::noteText($line)) {
                $error = new SystemFailure(null, new \LogicException('The idempotency key holds a different line.'));
                throw $error;
            }

            return $stored;
        }

        $inserted = $this->lines->insert(
            new LedgerLine(
                0,
                $line->registration,
                $line->kind,
                $line->payer,
                $line->amount,
                $line->source,
                $line->idempotencyKey,
                $valueDate,
                $line->paidAt,
                $context->now(),
                $line->method,
                $line->correction,
            ),
            $context
        );
        $note = self::noteText($line);
        if ($note !== null) {
            $this->notes->upsert(new PaymentNote($line->registration, $inserted->id, $note), $context);
        }

        return $inserted;
    }

    /**
     * The registration's lines for display, newest first, each with its note. A plain read, without a lock,
     * of one statement; rules never read it.
     *
     * @return list<LedgerEntry>
     */
    public function entriesOf(RegistrationKey $registration): array
    {
        return $this->listing->of($registration);
    }

    /**
     * Erases the registration's notes: the free text of its manual lines, which a privacy erase must reach
     * (the lines themselves are kept). The run must hold the registration's row lock. Returns how many it
     * erased. No erase use case exists yet; it calls this when it is built.
     *
     * @throws SystemFailure when the run does not hold the registration
     */
    public function eraseNotesOf(RegistrationKey $registration, Context $context): int
    {
        if (!$context->holdsRegistration($registration)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
            throw $error;
        }
        $notes = $this->notes->forRegistration($registration, $context);
        foreach ($notes as $note) {
            $this->notes->delete($note, $context);
        }

        return count($notes);
    }

    private function noteOf(LedgerLine $stored): ?string
    {
        foreach ($this->notes->forRegistration($stored->registration) as $note) {
            if ($note->lineId === $stored->id) {
                return $note->note;
            }
        }

        return null;
    }

    private static function noteFits(?string $note): bool
    {
        return $note === null || mb_check_encoding($note, 'UTF-8') && mb_strlen($note, 'UTF-8') <= 500;
    }

    private static function noteText(NewLine $line): ?string
    {
        return $line->note === null || trim($line->note) === '' ? null : trim($line->note);
    }

    /**
     * Whether the registration already holds a different line under the line's idempotency key, which
     * `record()` would refuse as a failure: for a caller that refuses a person's reused key with an
     * `IdempotencyConflict` first. A plain read; false for a new key and for the same line again.
     */
    public function keyHoldsDifferentLine(NewLine $line): bool
    {
        $stored = $this->lines->findByKey($line->registration, $line->idempotencyKey);

        return $stored !== null
            && (!self::isSame($stored, $line, $line->valueDate === null ? null : Instant::startOfDay($line->valueDate))
                || $this->noteOf($stored) !== self::noteText($line));
    }

    /** A plain read, without a lock. */
    public function balancesOf(RegistrationKey $registration): Balances
    {
        return $this->balances->of($registration);
    }

    /**
     * A plain read, without a lock.
     *
     * @return list<PaymentFact> ordered by `valueAt()`, then ID
     */
    public function paymentFactsOf(RegistrationKey $registration): array
    {
        return $this->facts->of($registration);
    }

    /**
     * A plain read, without a lock, for display (the participant blocks): each payer's charged, paid and
     * balance, and its lines that are not payment facts. Rules never read it; they read payment facts.
     */
    public function summaryOf(RegistrationKey $registration): Summary
    {
        return $this->summaries->of($registration);
    }

    /**
     * The registration's `charge` lines summed per source and payer: the materializer's read of what it
     * has recorded. Only `Pricing\Api\Materializer` calls it (a test checks this); rules never read charge
     * lines. A plain read, without a lock: the materializer runs under the registration's row lock.
     *
     * @return list<ChargeTotal>
     */
    public function chargeTotalsOf(RegistrationKey $registration): array
    {
        return $this->charges->of($registration);
    }

    private function check(NewLine $line): void
    {
        $problem = match (true) {
            !$line->kind->allows($line->amount) => 'The amount does not fit the kind.',
            !Payer::isValid($line->payer) => 'The payer is not a payer-kind ID.',
            preg_match('/\A[a-z0-9_:.-]{1,128}\z/', $line->idempotencyKey) !== 1 => 'The key is malformed.',
            ($line->valueDate !== null) !== $line->kind->carriesValueDate()
                || ($line->paidAt !== null) !== $line->kind->carriesPaidTime() => 'The time does not fit the kind.',
            $line->valueDate !== null && Instant::startOfDay($line->valueDate) === null
                => 'The value date is not a day.',
            $line->method !== null && !$line->kind->carriesMethod() => 'The kind has no method.',
            $line->correction && $line->kind !== LineKind::ManualRefund => 'Only a manual refund is a correction.',
            $line->correction && self::noteText($line) === null => 'A correction needs its reason.',
            $line->note !== null && !$line->kind->mayHaveNote() => 'The kind has no note.',
            !self::noteFits(self::noteText($line)) => 'The note is not 1 to 500 characters.',
            default => null,
        };
        if ($problem !== null) {
            $error = new SystemFailure(null, new \LogicException($problem));
            throw $error;
        }
    }

    private static function isSame(LedgerLine $stored, NewLine $line, ?\DateTimeImmutable $valueDate): bool
    {
        return $stored->kind === $line->kind
            && $stored->payer === $line->payer
            && $stored->amount->isEqualTo($line->amount)
            && $stored->source->type === $line->source->type
            && $stored->source->eventId === $line->source->eventId
            && $stored->source->key === $line->source->key
            && self::instant($stored->valueDate) === self::instant($valueDate)
            && self::instant($stored->paidAt) === self::instant($line->paidAt)
            && $stored->method === $line->method
            && $stored->correction === $line->correction;
    }

    private static function instant(?\DateTimeImmutable $time): ?string
    {
        return $time === null ? null : Instant::toStorage($time);
    }
}
