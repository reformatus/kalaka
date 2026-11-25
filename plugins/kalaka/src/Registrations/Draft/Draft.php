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
    /** A draft proper: a person's or a guest's. */
    public const KIND_DRAFT = 'draft';

    /**
     * A one-shot prefill (N676): a handoff's answers in an account mode with no drafts, kept under the hash of
     * a cookie token for `PREFILL_SECONDS` and deleted on its first read. Never a draft: no draft lookup finds
     * it, no claim takes it.
     */
    public const KIND_PREFILL = 'prefill';

    public const PREFILL_SECONDS = 300;

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
        public int $claims = 0,
        public ?string $resolvedClaim = null,
        public ?string $resolvedChoice = null,
        public readonly string $kind = self::KIND_DRAFT,
    ) {
    }

    /** A guest draft of that form, expired or not: what the submit's deletion of the cookie's draft asks. */
    public function isGuestOf(int $formId): bool
    {
        return $this->userId === null && $this->formId === $formId;
    }

    /** A guest draft that has not expired at `$now`. */
    public function isUsableGuest(\DateTimeImmutable $now): bool
    {
        return $this->userId === null && $this->expiresAt !== null && $this->expiresAt > $now;
    }

    /** A guest draft of that form that has not expired at `$now`: the one usable under a claim token. */
    public function isUsableGuestOf(int $formId, \DateTimeImmutable $now): bool
    {
        return $this->isGuestOf($formId) && $this->isUsableGuest($now);
    }
}
