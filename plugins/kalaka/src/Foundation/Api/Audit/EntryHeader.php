<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\Side;

/**
 * What is known about a run before it starts: the entry of each attempt, or the failed entry, is made from it.
 *
 * @internal
 */
final readonly class EntryHeader
{
    /**
     * @param list<Subject> $declared the subjects known from the checked input
     */
    public function __construct(
        public string $action,
        public Side $side,
        public Actor $actor,
        public ?string $reason,
        public array $declared,
    ) {
    }

    public function entry(\DateTimeImmutable $at): AuditEntry
    {
        return new AuditEntry($this->action, $this->side, $this->actor, $at, $this->reason, null, $this->declared);
    }

    /** No reason and no changes: only the error (foundation.md, "Audit"). */
    public function failed(\DateTimeImmutable $at, string $error): AuditEntry
    {
        return new AuditEntry($this->action, $this->side, $this->actor, $at, null, $error, $this->declared);
    }
}
