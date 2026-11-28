<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\BalanceQuery;
use Kalaka\Ledger\ChargeTotalQuery;
use Kalaka\Ledger\LedgerLineRepository;
use Kalaka\Ledger\LineListQuery;
use Kalaka\Ledger\ManualFeeRepository;
use Kalaka\Ledger\PaymentNote;
use Kalaka\Ledger\PaymentNoteRepository;
use Kalaka\Ledger\PaymentFactQuery;
use Kalaka\Ledger\StatementQuery;
use Kalaka\Ledger\SummaryQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/** The ledger's contract: the only way to write a line, and the plain reads. */
final class Ledger
{
    /** The per-event action that lets a grant holder see the money of the registrations of its view. */
    public const VIEW_MONEY = 'kalaka_view_money';

    /**
     * The per-event action of every manual money entry: a payment, a refund, a correction, a manual charge or
     * a manual discount (money.md, "Recording payments by hand"). It includes `VIEW_MONEY`. Its label, for
     * the grant editor, is „Pénzügyi tételek rögzítése” and its description „Befizetés, visszafizetés,
     * korrekció, díj vagy kedvezmény kézi rögzítése egy jelentkezőnél.” [UNAPPROVED T24, T25]; no action-label
     * registry exists yet, so no code renders them.
     */
    public const RECORD_MONEY = 'kalaka_record_money';

    public function __construct(
        private readonly LedgerLineRepository $lines = new LedgerLineRepository(),
        private readonly BalanceQuery $balances = new BalanceQuery(),
        private readonly PaymentFactQuery $facts = new PaymentFactQuery(),
        private readonly SummaryQuery $summaries = new SummaryQuery(),
        private readonly ChargeTotalQuery $charges = new ChargeTotalQuery(),
        private readonly PaymentNoteRepository $notes = new PaymentNoteRepository(),
        private readonly LineListQuery $listing = new LineListQuery(),
        private readonly ManualFeeRepository $fees = new ManualFeeRepository(),
        private readonly StatementQuery $statements = new StatementQuery(),
    ) {
    }

    /**
     * Appends the line, or returns the stored one when the registration already holds the same line
     * under the key. `RecordedLine::$inserted` tells the caller which happened, so a replay writes no
     * further record. A value date, a day, is stored as 00:00 of it in the site time zone at the moment
     * of recording. The run must hold the registration's row lock (`Context::holdsRegistration()`). The line's
     * free text, when it has one, is written to its own side record (`kalaka_payment_notes`), in the same run.
     *
     * @throws SystemFailure when the run does not hold the registration, the line breaks a rule, the key
     *                       holds a different line, or the insert fails
     */
    public function record(NewLine $line, Context $context): RecordedLine
    {
        if (!$context->holdsRegistration($line->registration)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
            throw $error;
        }
        $this->check($line);
        // A correction has no date of its own: it counts from the moment it is recorded.
        $valueDate = $line->correction ? $context->now() : self::dayOf($line);

        $stored = $this->lines->findByKey($line->registration, $line->idempotencyKey);
        if ($stored !== null) {
            $day = $line->correction ? $stored->valueDate : $valueDate;
            if (!self::isSame($stored, $line, $day) || !$this->sameNote($stored, self::noteText($line))) {
                $error = new SystemFailure(null, new \LogicException('The idempotency key holds a different line.'));
                throw $error;
            }

            return new RecordedLine($stored, false);
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

        return new RecordedLine($inserted, true);
    }

    /**
     * Appends a manual charge or discount (the fact the `manual` fee component composes), or returns the stored
     * entry when the registration already holds the same one under the key; `RecordedManualFee::$inserted` tells
     * which. The same key with another entry is an error. A stored entry whose title was erased compares as
     * no title. The run must hold the registration's row lock. Writing it marks the registration touched, so
     * the evaluation of the same run composes it into a `charge` line.
     *
     * @throws SystemFailure when the run does not hold the registration, the entry breaks a rule, the key
     *                       holds a different entry, or the insert fails
     */
    public function recordManualFee(ManualFee $fee, Context $context): RecordedManualFee
    {
        if (!$context->holdsRegistration($fee->registration)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
            throw $error;
        }
        $title = $fee->title === null ? null : trim($fee->title);
        $problem = match (true) {
            !$fee->amount->isPositive() => 'The amount is not positive.',
            !Payer::isValid($fee->payer) => 'The payer is not a payer-kind ID.',
            preg_match('/\A[a-z0-9_:.-]{1,128}\z/', $fee->idempotencyKey) !== 1 => 'The key is malformed.',
            $title === null || $title === '' => 'A manual fee needs its title.',
            !mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 191
                => 'The title is not 1 to 191 characters.',
            default => null,
        };
        if ($problem !== null) {
            $error = new SystemFailure(null, new \LogicException($problem));
            throw $error;
        }

        $stored = $this->fees->findByKey($fee->registration, $fee->idempotencyKey);
        if ($stored !== null) {
            if (!self::isSameFee($stored, $fee, $title)) {
                $error = new SystemFailure(null, new \LogicException('The idempotency key holds a different entry.'));
                throw $error;
            }

            return new RecordedManualFee($stored, false);
        }

        return new RecordedManualFee(
            $this->fees->upsert(
                new ManualFee(
                    0,
                    $fee->registration,
                    $fee->type,
                    $fee->payer,
                    $fee->amount,
                    $title,
                    $fee->idempotencyKey,
                    $fee->recordedBy,
                    $context->now(),
                ),
                $context
            ),
            true
        );
    }

    /**
     * Whether the registration already holds a different manual fee under the key, which `recordManualFee()`
     * would refuse as a failure: for a caller that refuses a person's reused key with an `IdempotencyConflict`
     * first. A plain read; false for a new key and for the same entry again.
     */
    public function keyHoldsDifferentFee(ManualFee $fee): bool
    {
        $stored = $this->fees->findByKey($fee->registration, $fee->idempotencyKey);

        return $stored !== null && !self::isSameFee($stored, $fee, $fee->title === null ? null : trim($fee->title));
    }

    /** The manual fee the registration holds under the key, if any. A plain read. */
    public function manualFeeUnderKey(RegistrationKey $registration, string $idempotencyKey): ?ManualFee
    {
        return $this->fees->findByKey($registration, $idempotencyKey);
    }

    /** Whether the registration holds a ledger line under the key. A plain read. */
    public function holdsLineKey(RegistrationKey $registration, string $idempotencyKey): bool
    {
        return $this->lines->findByKey($registration, $idempotencyKey) !== null;
    }

    /**
     * Whether the registration holds a ledger line whose idempotency key starts with the prefix, matched
     * literally: for a reader that knows a key's front part and not the rest (a provider's request, whose items
     * name the end). A plain read.
     */
    public function holdsLineKeyStartingWith(RegistrationKey $registration, string $prefix): bool
    {
        return $this->lines->existsWithKeyPrefix($registration, $prefix);
    }

    /**
     * The registration's manual fees, oldest first: a fact read, never a line read, for the `manual` fee
     * component. A plain read, without a lock.
     *
     * @return list<ManualFee>
     */
    public function manualFeesOf(RegistrationKey $registration): array
    {
        return $this->fees->forRegistration($registration);
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
     * Erases the registration's notes and manual fee titles: the free text of its manual entries, which a
     * privacy erase must reach (the lines themselves are kept). The run must hold the registration's row lock.
     * Returns how many it erased. No erase use case exists yet; it calls this when it is built.
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
        // The title of a manual fee is free text too: the fact and its line stay, only the words go.
        $erased = count($notes);
        foreach ($this->fees->forRegistration($registration, $context) as $fee) {
            if ($fee->title !== null) {
                $fee->title = null;
                $this->fees->upsert($fee, $context);
                ++$erased;
            }
        }

        return $erased;
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

    /**
     * Whether the stored line's note matches the incoming one. A line with no stored note compares no note:
     * it never had one, or `eraseNotesOf()` erased it, and the table keeps no trace of which. A replay of the
     * original entry after an erase therefore still finds its line; the same key with another amount is still
     * a different line.
     */
    private function sameNote(LedgerLine $stored, ?string $note): bool
    {
        $storedNote = $this->noteOf($stored);

        return $storedNote === null || $storedNote === $note;
    }

    private static function dayOf(NewLine $line): ?\DateTimeImmutable
    {
        return $line->valueDate === null ? null : Instant::startOfDay($line->valueDate);
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
            && (!self::isSame($stored, $line, $line->correction ? $stored->valueDate : self::dayOf($line))
                || !$this->sameNote($stored, self::noteText($line)));
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
     * A plain read, without a lock, for display (the participant blocks' money table): one payer's owed lines
     * and money movements with their notes, and its totals, from one statement. Empty and zero in `$currency`,
     * the event's, when the payer has no lines. Rules never read it.
     */
    public function statementOf(RegistrationKey $registration, string $payer, Currency $currency): PayerStatement
    {
        return $this->statements->of($registration, $payer, $currency);
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
            ($line->valueDate !== null) !== ($line->kind->carriesValueDate() && !$line->correction)
                || ($line->paidAt !== null) !== $line->kind->carriesPaidTime() => 'The time does not fit the kind.',
            $line->valueDate !== null && Instant::startOfDay($line->valueDate) === null
                => 'The value date is not a day.',
            $line->method !== null && !$line->kind->carriesMethod() => 'The kind has no method.',
            $line->correction && !$line->kind->carriesMethod() => 'Only a manual payment or refund is a correction.',
            $line->correction && $line->method !== null => 'A correction has no method.',
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

    private static function isSameFee(ManualFee $stored, ManualFee $fee, ?string $title): bool
    {
        return $stored->type === $fee->type
            && $stored->payer === $fee->payer
            && $stored->amount->isEqualTo($fee->amount)
            && ($stored->title === null || $stored->title === $title);
    }

    private static function instant(?\DateTimeImmutable $time): ?string
    {
        return $time === null ? null : Instant::toStorage($time);
    }
}
