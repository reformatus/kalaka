<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;

/**
 * A form's answers kept before its submit (S74, owner ruling 2026-11-14), keyed by event first. A **user
 * draft** (`$userId` set) is a logged-in person's, kept as they type (the autosave) and found on any device.
 * A **guest draft** (`$userId` null) holds what a link or the quiz handed over and the guest's own typing (the
 * autosave, owner ruling 2026-11-26), under the SHA-256 of a claim token in the visitor's cookie, until the
 * form is sent or the event's registration has closed (the periodic sweep; owner ruling 2026-11-26). Logged
 * in, the person claims it: it becomes their user draft, or waits for their choice against the one they have.
 *
 * `$claims` counts the claims that changed what a user draft holds (the guest draft became it, or a merge or
 * keep_link). The autosave carries the count its page opened with, so a save sent before a claim never
 * writes over what the claim brought (`SaveDraft`). The row's `$version` cannot tell: every save bumps it.
 *
 * `$resolvedClaim` is the claim hash of the last guest draft settled into a user draft, with the choice it
 * was settled with (`$resolvedChoice`; null when it became theirs on the form's page, with no choice). A
 * repeated choice is answered from them once the guest draft is gone (`ResolveDraftClaim`).
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
        public readonly ?int $id = null,
        public readonly int $version = 1,
        public int $claims = 0,
        public ?string $resolvedClaim = null,
        public ?string $resolvedChoice = null,
    ) {
    }

    /** A guest draft of that form: what a claim, a choice and the submit's deletion of the cookie's draft ask. */
    public function isGuestOf(int $formId): bool
    {
        return $this->userId === null && $this->formId === $formId;
    }
}
