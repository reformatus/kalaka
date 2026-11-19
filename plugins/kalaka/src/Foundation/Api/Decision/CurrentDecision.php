<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Api\Audit\Subject;

/**
 * The current decision of one kind of a registration, for a reader outside Foundation; the time is UTC. The
 * cause is the record's; a base state (`DecisionDefault`) has none.
 */
final readonly class CurrentDecision
{
    public function __construct(
        public string $kind,
        public string $state,
        public \DateTimeImmutable $effectiveAt,
        public ?Subject $cause = null,
    ) {
    }
}
