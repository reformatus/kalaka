<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/**
 * A declared decision kind: its Hungarian label, its states, each with its Hungarian label, and its base state
 * if it declares one. [UNAPPROVED N1, N7]
 */
final readonly class DecisionKind
{
    /** @param array<string, string> $states state => label, in declared order */
    public function __construct(
        public string $kind,
        public string $label,
        public array $states,
        public ?DecisionDefault $default = null,
    ) {
    }
}
