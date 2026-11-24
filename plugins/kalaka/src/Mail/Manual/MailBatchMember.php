<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

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
}
