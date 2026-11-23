<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/**
 * A transition rule of one decision kind: it returns its transitions, none, one or more in order, and never
 * writes. Core writes each one that changes the kind's running state. [UNAPPROVED N16]
 */
interface DecisionRule
{
    public function kind(): string;

    /** The kind's Hungarian label, as the grid shows it. */
    public function label(): string;

    /** @return array<string, string> the states, each with its Hungarian label, in display order */
    public function states(): array;

    /**
     * The kind's base state, derived where it has no record, and the statuses it is defined for; null: no
     * base state, so no record reads as no state in every status. [UNAPPROVED N7]
     */
    public function defaultState(): ?DecisionDefault;

    /** @return list<Transition> applied in order; the rule runs its own checks in a row when one answer needs two */
    public function decide(DecisionInput $input): array;
}
