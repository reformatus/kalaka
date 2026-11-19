<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;

/**
 * A payment-fact line as recorded; the value date, the paid time and the recorded time are UTC instants. The
 * recorded time is the run's moment that wrote it, so a rule can tell a line its own run wrote.
 */
final readonly class PaymentFact
{
    public function __construct(
        public int $id,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public ?\DateTimeImmutable $valueDate,
        public ?\DateTimeImmutable $paidAt,
        public ?\DateTimeImmutable $recordedAt = null,
    ) {
    }

    /** The paid time, or the value date: the instant the payment counts from. */
    public function valueAt(): \DateTimeImmutable
    {
        if ($this->paidAt !== null) {
            return $this->paidAt;
        }
        if ($this->valueDate === null) {
            $error = new SystemFailure(null, new \LogicException('A payment fact has neither time.'));
            throw $error;
        }

        return $this->valueDate;
    }
}
