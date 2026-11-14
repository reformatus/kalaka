<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/** The current decision of one kind of a registration, for a reader outside Foundation; the time is UTC. */
final readonly class CurrentDecision
{
    public function __construct(
        public string $kind,
        public string $state,
        public \DateTimeImmutable $effectiveAt,
    ) {
    }
}
