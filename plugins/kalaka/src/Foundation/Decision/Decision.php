<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Audit\Subject;

/** The current decision of one kind, as a rule reads it; times are in UTC. */
final readonly class Decision
{
    public function __construct(
        public string $kind,
        public string $state,
        public \DateTimeImmutable $effectiveAt,
        public \DateTimeImmutable $recordedAt,
        public Subject $cause,
    ) {
    }
}
