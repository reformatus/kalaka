<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Time\Instant;

/**
 * One send of a manual mail (mail.md, "Five kinds of mail", Manual: "keyed by the send batch"): the chosen
 * body, who sent it and when, and how many registrations it queued and
 * skipped. Its members (`MailBatchMember`) are the audience, frozen at the send; the batch is the cause of
 * each of its deliveries (`CAUSE`, its ID).
 */
final class MailBatch
{
    /** The delivery cause's record type, and the mail key of every manual delivery. */
    public const CAUSE = 'mail_batch';
    public const MAIL = 'manual';

    /**
     * @param ?string $requestKey the caller's repeat guard; unique within the event
     * @param ?int $createdBy the WordPress user; null for the system and for WP-CLI without a user
     */
    public function __construct(
        public readonly int $eventId,
        public readonly int $bodyId,
        public readonly ?string $requestKey,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?int $createdBy,
        public readonly int $queued,
        public readonly int $skipped,
        public readonly int $id = 0,
        public readonly int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row a `kalaka_mail_batches` row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['event_id'],
            (int) $row['body_id'],
            $row['request_key'],
            Instant::fromStorage((string) $row['created_at']),
            $row['created_by'] === null ? null : (int) $row['created_by'],
            (int) $row['queued'],
            (int) $row['skipped'],
            (int) $row['id'],
            (int) $row['version'],
        );
    }
}
