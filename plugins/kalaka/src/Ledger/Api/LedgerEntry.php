<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;

/**
 * One ledger line as the admin shows it: the stored signed amount (a payment is negative, a refund positive),
 * with its note. Charge lines are included; this is a display read, never a rule's.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public int $id,
        public LineKind $kind,
        public string $payer,
        public Money $amount,
        public Subject $source,
        public ?\DateTimeImmutable $valueDate,
        public ?\DateTimeImmutable $paidAt,
        public \DateTimeImmutable $recordedAt,
        public ?PaymentMethod $method,
        public bool $correction,
        public ?string $note,
    ) {
    }
}
