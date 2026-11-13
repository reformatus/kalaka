<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Condition\Condition;

/**
 * A branch a field sits in: the conditions of the earlier branches of its chain (which must not
 * hold) and its own (none for `kalaka/else`). The field is visible when none of the earlier ones
 * holds and the own one holds or is absent.
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
    ) {
    }
}
