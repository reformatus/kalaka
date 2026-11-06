<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

/** A transition rule of one decision kind: it returns a transition or nothing and never writes. */
interface DecisionRule
{
    public function kind(): string;

    /** @return list<string> */
    public function states(): array;

    public function decide(DecisionInput $input): ?Transition;
}
