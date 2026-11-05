<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;

/** A payment-fact line as recorded; the paid time is in UTC. */
final readonly class PaymentFact
{
    /**
     * @param ?string $valueDate a calendar day, `Y-m-d`
     * @param ?\DateTimeImmutable $paidAt
     */
    public function __construct(
        public int $id,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public ?string $valueDate,
        public ?\DateTimeImmutable $paidAt,
    ) {
    }

    /** The paid time, or local 00:00 of the value date in the site's time zone, as `EventRecord::startOf()` does. */
    public function valueAt(): \DateTimeImmutable
    {
        if ($this->paidAt !== null) {
            return $this->paidAt;
        }
        if ($this->valueDate === null) {
            $error = new SystemFailure(null, new \LogicException('A payment fact has neither time.'));
            throw $error;
        }

        return new \DateTimeImmutable($this->valueDate . ' 00:00:00', wp_timezone());
    }
}
