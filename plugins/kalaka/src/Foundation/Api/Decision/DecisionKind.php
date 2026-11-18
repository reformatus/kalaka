<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/** A declared decision kind: its Hungarian label and its states, each with its Hungarian label. [UNAPPROVED N1] */
final readonly class DecisionKind
{
    /** @param array<string, string> $states state => label, in declared order */
    public function __construct(
        public string $kind,
        public string $label,
        public array $states,
    ) {
    }
}
