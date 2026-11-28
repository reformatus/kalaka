<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Api\Audit\Subject;

/**
 * What a rule decides: the new state, when it took effect, and the record that caused it. A rule returns
 * its transitions in order; core writes each one that changes the running state (foundation.md, "Facts,
 * decisions and computed values").
 */
final readonly class Transition
{
    public function __construct(
        public string $state,
        public \DateTimeImmutable $effectiveAt,
        public Subject $cause,
    ) {
    }
}
