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
     * @param ?string $valueDate a calendar day, `Y-m-d`, stored as 00:00 of it in the site time zone;
     *                           on `manual_payment` and `manual_refund`, never on a correction (it counts
     *                           from its recording)
     * @param ?\DateTimeImmutable $paidAt on `payment` and `refund`
     * @param ?PaymentMethod $method on `manual_payment` and `manual_refund`
     * @param bool $correction on `manual_payment` and `manual_refund`: the owner's ruling 2026-11-19, a correction of
     *                         either direction, with no method and no value date; its `$note`, the reason, is optional
     * @param ?string $note free text (a bank reference, a payer note, a reason) of at most 500 characters: kept
     *                      in a side record that erasure can reach, never in the ledger line
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
        public ?PaymentMethod $method = null,
        public bool $correction = false,
        public ?string $note = null,
    ) {
    }
}
