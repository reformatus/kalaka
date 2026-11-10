<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * One row of the send log: one mail to one registration on one occurrence, which the cause (a record
 * type and its key) identifies. mail.md, "Words".
 */
final class Delivery
{
    /**
     * @param int $attempts the claims so far; an attempt that ended in an error leaves the delivery pending
     * @param ?string $error the latest error, masked (`DeliveryError::text()`); null when none was reported
     */
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly string $mail,
        public readonly string $causeType,
        public readonly string $causeKey,
        public readonly MailKind $kind,
        public DeliveryStatus $status,
        public readonly \DateTimeImmutable $queuedAt,
        public int $attempts = 0,
        public ?\DateTimeImmutable $claimedAt = null,
        public ?\DateTimeImmutable $sentAt = null,
        public ?string $mailVersion = null,
        public ?string $error = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row a `kalaka_deliveries` row */
    public static function fromRow(array $row): self
    {
        return new self(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['mail'],
            (string) $row['cause_type'],
            (string) $row['cause_key'],
            MailKind::from((string) $row['kind']),
            DeliveryStatus::from((string) $row['status']),
            Instant::fromStorage((string) $row['queued_at']),
            (int) $row['attempts'],
            $row['claimed_at'] === null ? null : Instant::fromStorage($row['claimed_at']),
            $row['sent_at'] === null ? null : Instant::fromStorage($row['sent_at']),
            $row['mail_version'],
            $row['error'],
            (int) $row['id'],
            (int) $row['version'],
        );
    }
}
