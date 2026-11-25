<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Manual\MailBatch;

/**
 * A manual mail's send batch as the screen reads it: the batch's own record (who, when, which body, how many
 * were queued and skipped), and with one batch also its members. Never an address, a name or the request key.
 */
final readonly class BatchInfo
{
    /**
     * @param ?string $subject the body's title now; null when the body is trashed or missing, then `bodyGone` is true
     * @param ?int $createdBy the user; null for the system and for WP-CLI without a user
     * @param ?list<array{code: string, outcome: string}> $members null in a list; set for one batch
     */
    public function __construct(
        public int $id,
        public int $bodyId,
        public ?string $subject,
        public bool $layout,
        public \DateTimeImmutable $createdAt,
        public ?int $createdBy,
        public int $queued,
        public int $skipped,
        public ?array $members = null,
        public bool $bodyGone = false,
    ) {
    }

    /** @param ?list<array{code: string, outcome: string}> $members */
    public static function of(MailBatch $batch, ?string $subject, ?array $members = null): self
    {
        return new self(
            $batch->id,
            $batch->bodyId,
            $subject,
            $batch->layout,
            $batch->createdAt,
            $batch->createdBy,
            $batch->queued,
            $batch->skipped,
            $members,
            $subject === null
        );
    }
}
