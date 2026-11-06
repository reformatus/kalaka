<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationKey;

/** One stored ledger line; the paid time and the recording time are in UTC. */
final readonly class LedgerLine
{
    public function __construct(
        public int $id,
        public RegistrationKey $registration,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public Subject $source,
        public string $idempotencyKey,
        public ?string $valueDate,
        public ?\DateTimeImmutable $paidAt,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
