<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * One registration of a send batch and what became of it: queued, or skipped because it was not registered
 * (withdrawn, removed or waitlisted). A code only, never an address, a name or rendered content (the address is
 * read at send time).
 */
final readonly class MailBatchMember
{
    public const QUEUED = 'queued';
    public const SKIPPED_WITHDRAWN = 'skipped_withdrawn';
    public const SKIPPED_REMOVED = 'skipped_removed';
    public const SKIPPED_WAITLISTED = 'skipped_waitlisted';

    public function __construct(
        public int $batchId,
        public int $eventId,
        public string $code,
        public string $outcome,
    ) {
    }

    /**
     * The one rule of who a manual mail skips: whoever is not registered, with the reason by status (owner
     * 2026-11-27, walkthrough 7.1: a waitlisted registration is skipped like a withdrawn or removed one).
     * `SendManualMail::plan()` asks it, for the use case and the preview alike.
     *
     * The match has no default arm, so a new status cannot be queued silently. When main's
     * `RegistrationStatus::isLive()` (N1163) is merged into this line, the condition becomes
     * `$status->isLive()` (queue) with this match only naming the reason of the rest.
     */
    public static function outcomeOf(RegistrationStatus $status): string
    {
        return match ($status) {
            RegistrationStatus::Registered => self::QUEUED,
            RegistrationStatus::Waitlisted => self::SKIPPED_WAITLISTED,
            RegistrationStatus::Withdrawn => self::SKIPPED_WITHDRAWN,
            RegistrationStatus::Removed => self::SKIPPED_REMOVED,
        };
    }

    /** The reason a skipped registration is listed with: `withdrawn`, `removed` or `waitlisted`. */
    public static function reasonOf(string $outcome): string
    {
        return match ($outcome) {
            self::SKIPPED_WITHDRAWN => 'withdrawn',
            self::SKIPPED_WAITLISTED => 'waitlisted',
            default => 'removed',
        };
    }
}
