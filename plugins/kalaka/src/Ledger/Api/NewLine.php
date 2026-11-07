<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationKey;

/** A line to record; `Ledger::record()` checks it. */
final readonly class NewLine
{
    /**
     * @param string $idempotencyKey 1 to 128 characters of `[a-z0-9_:.-]`, unique within the registration
     * @param ?string $valueDate a calendar day, `Y-m-d`, stored as 00:00 of it in the site time zone; on `manual_payment`, `manual_refund` and `transfer`
     * @param ?\DateTimeImmutable $paidAt on `payment` and `refund`
     */
    public function __construct(
        public RegistrationKey $registration,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public Subject $source,
        public string $idempotencyKey,
        public ?string $valueDate = null,
        public ?\DateTimeImmutable $paidAt = null,
    ) {
    }
}
