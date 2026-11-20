<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/** A transition rule of one decision kind: it returns a transition or nothing and never writes. */
interface DecisionRule
{
    public function kind(): string;

    /** The kind's Hungarian label, as the grid shows it. [UNAPPROVED N1] */
    public function label(): string;

    /** @return array<string, string> the states, each with its Hungarian label, in display order [UNAPPROVED N1] */
    public function states(): array;

    /**
     * The kind's base state, derived where it has no record, and the statuses it is defined for; null: no
     * base state, so no record reads as no state in every status. [UNAPPROVED N7]
     */
    public function defaultState(): ?DecisionDefault;

    public function decide(DecisionInput $input): ?Transition;
}
