<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A manual charge or discount (money.md, "Recording payments by hand"): one fact of one registration, one
 * payer, a positive amount and a title the registrant sees. The type gives the sign. The title is free text,
 * so erasure reaches it: it is null only after an erase.
 */
final class ManualFee
{
    public function __construct(
        public readonly int $id,
        public readonly RegistrationKey $registration,
        public readonly ManualFeeType $type,
        public readonly string $payer,
        public readonly Money $amount,
        public ?string $title,
        public readonly string $idempotencyKey,
        public readonly ?int $recordedBy,
        public readonly \DateTimeImmutable $recordedAt,
        public int $version = 0,
    ) {
    }

    /** What the entry adds to what the payer owes: plus the amount for a charge, minus it for a discount. */
    public function signedAmount(): Money
    {
        return $this->type === ManualFeeType::Charge ? $this->amount : $this->amount->negated();
    }
}
