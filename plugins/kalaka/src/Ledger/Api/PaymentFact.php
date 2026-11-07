<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;

/** A payment-fact line as recorded; the value date and the paid time are UTC instants. */
final readonly class PaymentFact
{
    public function __construct(
        public int $id,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public ?\DateTimeImmutable $valueDate,
        public ?\DateTimeImmutable $paidAt,
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
