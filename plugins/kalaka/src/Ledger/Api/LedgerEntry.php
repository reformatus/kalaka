<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
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

    /** The paid time, or the value date: the instant a paid-side line counts from. Not for an owed line. */
    public function valueAt(): \DateTimeImmutable
    {
        $at = $this->paidAt ?? $this->valueDate;
        if ($at === null) {
            $error = new SystemFailure(null, new \LogicException('A line without a paid time or a value date.'));
            throw $error;
        }

        return $at;
    }

    /** The day `valueAt()` falls on in the site time zone, `Y-m-d`. */
    public function valueDay(): string
    {
        return $this->valueAt()->setTimezone(wp_timezone())->format('Y-m-d');
    }
}
