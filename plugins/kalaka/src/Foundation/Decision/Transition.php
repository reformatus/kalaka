<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Audit\Subject;

/** What a rule decides: the new state, when it took effect, and the record that caused it. */
final readonly class Transition
{
    public function __construct(
        public string $state,
        public \DateTimeImmutable $effectiveAt,
        public Subject $cause,
    ) {
    }
}
