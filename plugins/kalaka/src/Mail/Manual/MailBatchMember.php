<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * One registration of a send batch and what became of it: queued, or skipped because it was withdrawn or
 * removed. A code only, never an address, a name or rendered content (the address is read at send time).
 */
final readonly class MailBatchMember
{
    public const QUEUED = 'queued';
    public const SKIPPED_WITHDRAWN = 'skipped_withdrawn';
    public const SKIPPED_REMOVED = 'skipped_removed';

    public function __construct(
        public int $batchId,
        public int $eventId,
        public string $code,
        public string $outcome,
    ) {
    }

    /**
     * The one rule of who a manual mail skips: a withdrawn or removed registration; every other status is sent
     * to, a waitlisted one included. `SendManualMail::plan()` asks it, for the use case and the preview alike.
     */
    public static function outcomeOf(RegistrationStatus $status): string
    {
        return match ($status) {
            RegistrationStatus::Withdrawn => self::SKIPPED_WITHDRAWN,
            RegistrationStatus::Removed => self::SKIPPED_REMOVED,
            default => self::QUEUED,
        };
    }

    /** The reason a skipped registration is listed with: `withdrawn`, or `removed`. */
    public static function reasonOf(string $outcome): string
    {
        return $outcome === self::SKIPPED_WITHDRAWN ? 'withdrawn' : 'removed';
    }
}
