<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Engine\Condition\Condition;

/**
 * A branch a field sits in: the conditions of the earlier branches of its chain (which must not
 * hold) and its own (none for `kalaka/else`). The field is visible when none of the earlier ones
 * holds and the own one holds or is absent.
 *
 * `chain` is the chain's number in the form, from 0 in document order (a chain before the chains
 * inside its branches), and `index` the branch's place in its chain, from 0.
 *
 * The conditions are parsed `Condition` objects of the Forms root; outside Forms a branch is passed along,
 * never inspected (N1).
 */
final readonly class FormBranch
{
    /** @param list<Condition> $earlier */
    public function __construct(
        public array $earlier,
        public ?Condition $own,
        public int $chain = 0,
        public int $index = 0,
    ) {
    }
}
