<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\BalanceQuery;
use Kalaka\Ledger\ChargeTotalQuery;
use Kalaka\Ledger\LedgerLineRepository;
use Kalaka\Ledger\PaymentFactQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/** The ledger's contract: the only way to write a line, and the plain reads. */
final class Ledger
{
    public function __construct(
        private readonly LedgerLineRepository $lines = new LedgerLineRepository(),
        private readonly BalanceQuery $balances = new BalanceQuery(),
        private readonly PaymentFactQuery $facts = new PaymentFactQuery(),
        private readonly ChargeTotalQuery $charges = new ChargeTotalQuery(),
    ) {
    }

    /**
     * Appends the line, or returns the stored one when the registration already holds the same line
     * under the key. A value date, a day, is stored as 00:00 of it in the site time zone at the moment
     * of recording. The run must hold the registration's row lock (`Context::holdsRegistration()`).
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
            if (!self::isSame($stored, $line, $valueDate)) {
                $error = new SystemFailure(null, new \LogicException('The idempotency key holds a different line.'));
                throw $error;
            }

            return $stored;
        }

        return $this->lines->insert(
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
            ),
            $context
        );
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
            && self::instant($stored->paidAt) === self::instant($line->paidAt);
    }

    private static function instant(?\DateTimeImmutable $time): ?string
    {
        return $time === null ? null : Instant::toStorage($time);
    }
}
