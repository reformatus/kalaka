<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The public reader of a registration's current decisions (foundation.md, "Facts, decisions and computed
 * values"): the latest record of each kind. Plain reads, one statement each; they never evaluate, so a
 * page that shows registrations writes nothing.
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

    /**
     * The current state of each listed kind for each listed code of one event, in one statement.
     *
     * @param list<string> $codes
     * @param list<string> $kinds
     * @return array<string, array<string, string>> the states by code and kind; no record, no entry
     */
    public function currentStates(int $eventId, array $codes, array $kinds): array
    {
        return $this->query->currentStates($eventId, $codes, $kinds);
    }

    /**
     * Every decision kind recorded in an event, in byte order.
     *
     * @return list<string>
     */
    public function kindsIn(int $eventId): array
    {
        return $this->query->kindsIn($eventId);
    }
}
