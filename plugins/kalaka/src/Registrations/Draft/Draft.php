<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;

/**
 * A form's answers kept before its submit (S74, owner ruling 2026-11-14), keyed by event first. A **user
 * draft** (`$userId` set) is a logged-in person's, kept as they type (the autosave) and found on any device.
 * A **guest draft** (`$userId` null) holds only what a link or the quiz handed over, under the SHA-256 of a
 * claim token in the visitor's cookie, until `$expiresAt`; a guest's own typing is never kept. Logged in,
 * the person claims it: it becomes their user draft, or waits for their choice against the one they have.
 */
final class Draft
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $formId,
        public int $formVersion,
        public ?int $userId,
        public ?string $claimHash,
        public AnswerSet $answers,
        public int $page,
        public readonly \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $expiresAt,
        public readonly ?int $id = null,
        public readonly int $version = 1,
    ) {
    }
}
