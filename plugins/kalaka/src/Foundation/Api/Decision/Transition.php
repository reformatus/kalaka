<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Api\Audit\Subject;

/**
 * What a rule decides: the new state, when it took effect, and the record that caused it. With `decideAgain`,
 * the evaluation asks the same rule once more in the same pass, right after writing this transition; that
 * second answer is final and its own `decideAgain` is ignored (foundation.md, "Facts, decisions and computed
 * values"). [UNAPPROVED N14]
 */
final readonly class Transition
{
    public function __construct(
        public string $state,
        public \DateTimeImmutable $effectiveAt,
        public Subject $cause,
        public bool $decideAgain = false,
    ) {
    }
}
