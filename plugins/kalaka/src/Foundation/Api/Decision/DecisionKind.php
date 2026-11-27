<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/**
 * A declared decision kind: its Hungarian label, its states, each with its Hungarian label, its base state
 * if it declares one [UNAPPROVED N7], and its marks, the icon and tone each state is shown with.
 */
final readonly class DecisionKind
{
    /**
     * @param array<string, string> $states state => label, in declared order
     * @param array<string, array{icon: string, tone: string}> $marks state => its icon (a Dashicons name) and tone
     *     (a tone name, '' for none); a state with no entry has no mark
     */
    public function __construct(
        public string $kind,
        public string $label,
        public array $states,
        public ?DecisionDefault $default = null,
        public array $marks = [],
    ) {
    }
}
