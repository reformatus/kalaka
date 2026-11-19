<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The public reader of decisions (foundation.md, "Facts, decisions and computed values"): the declared
 * kinds with their Hungarian labels and labelled states, read from the registry the evaluation uses, and
 * a registration's current decisions, the latest record of each kind. Plain reads, one statement each at
 * most; they never evaluate, so a page that shows registrations writes nothing.
 */
final class Decisions
{
    /** @param ?DecisionRules $rules the declarations; none: the registry the evaluation uses [UNAPPROVED N1] */
    public function __construct(
        private readonly DecisionQuery $query = new DecisionQuery(),
        private readonly ?DecisionRules $rules = null,
    ) {
    }

    /**
     * Every declared decision kind, in reading order: core first, then extensions, each as registered.
     * [UNAPPROVED N1]
     *
     * @return list<DecisionKind>
     */
    public function kinds(): array
    {
        return array_map(
            static fn (DecisionRule $rule): DecisionKind => new DecisionKind(
                $rule->kind(),
                $rule->label(),
                $rule->states()
            ),
            ($this->rules ?? DecisionRules::current())->all()
        );
    }

    /** The declared kind, or null when nothing declares it. [UNAPPROVED N1] */
    public function kind(string $kind): ?DecisionKind
    {
        foreach ($this->kinds() as $declared) {
            if ($declared->kind === $kind) {
                return $declared;
            }
        }

        return null;
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

    /**
     * The distinct states recorded for a kind in an event, in byte order. [UNAPPROVED N1]
     *
     * @return list<string>
     */
    public function statesIn(int $eventId, string $kind): array
    {
        return $this->query->statesIn($eventId, $kind);
    }
}
