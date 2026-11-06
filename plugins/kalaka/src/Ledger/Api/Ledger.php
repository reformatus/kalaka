<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\BalanceQuery;
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
    ) {
    }

    /**
     * Appends the line, or returns the stored one when the registration already holds the same line
     * under the key. A writer that can race locks the registration row first.
     *
     * @throws SystemFailure when the line breaks a rule, the key holds a different line, or the insert fails
     */
    public function record(NewLine $line, Context $context): LedgerLine
    {
        $this->check($line);

        $stored = $this->lines->findByKey($line->registration, $line->idempotencyKey);
        if ($stored !== null) {
            if (!self::isSame($stored, $line)) {
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
                $line->valueDate,
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

    private function check(NewLine $line): void
    {
        $problem = match (true) {
            !$line->kind->allows($line->amount) => 'The amount does not fit the kind.',
            !Payer::isValid($line->payer) => 'The payer is not a payer-kind ID.',
            preg_match('/\A[a-z0-9_:.-]{1,128}\z/', $line->idempotencyKey) !== 1 => 'The key is malformed.',
            ($line->valueDate !== null) !== $line->kind->carriesValueDate()
                || ($line->paidAt !== null) !== $line->kind->carriesPaidTime() => 'The time does not fit the kind.',
            $line->valueDate !== null && !self::isCalendarDay($line->valueDate) => 'The value date is not a day.',
            default => null,
        };
        if ($problem !== null) {
            $error = new SystemFailure(null, new \LogicException($problem));
            throw $error;
        }
    }

    private static function isCalendarDay(string $day): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $day;
    }

    private static function isSame(LedgerLine $stored, NewLine $line): bool
    {
        return $stored->kind === $line->kind
            && $stored->payer === $line->payer
            && $stored->amount->isEqualTo($line->amount)
            && $stored->source->type === $line->source->type
            && $stored->source->eventId === $line->source->eventId
            && $stored->source->key === $line->source->key
            && $stored->valueDate === $line->valueDate
            && self::instant($stored->paidAt) === self::instant($line->paidAt);
    }

    private static function instant(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
