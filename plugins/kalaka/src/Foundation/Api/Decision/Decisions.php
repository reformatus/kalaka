<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The public reader of a registration's current decisions (foundation.md, "Facts, decisions and computed
 * values"): the latest record of each kind. A plain read in one statement; it never evaluates, so a page
 * that shows a registration writes nothing.
 */
final class Decisions
{
    public function __construct(private readonly DecisionQuery $query = new DecisionQuery())
    {
    }

    /** @return array<string, CurrentDecision> by kind */
    public function currentOf(RegistrationKey $registration): array
    {
        $current = [];
        foreach ($this->query->currentOf($registration) as $kind => $decision) {
            $current[$kind] = new CurrentDecision($decision->kind, $decision->state, $decision->effectiveAt);
        }

        return $current;
    }
}
