<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Decision\Decisions;

/**
 * The states a decision column's sum can count (S37): those the kind's module declares, else the states
 * recorded in the event. Read before a run, as the options of a field are.
 */
final class DecisionStates
{
    /** @return list<string> */
    public static function of(int $eventId, string $kind): array
    {
        $decisions = new Decisions();
        $declared = $decisions->kind($kind);

        if ($declared !== null) {
            return array_map('strval', array_keys($declared->states));
        }

        return $decisions->statesIn($eventId, $kind);
    }
}
