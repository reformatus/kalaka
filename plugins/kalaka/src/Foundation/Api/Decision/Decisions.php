<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The public reader of decisions (foundation.md, "Facts, decisions and computed values"): the declared
 * kinds with their Hungarian labels and labelled states, read from the registry the evaluation uses, and
 * a registration's current decisions, the latest record of each kind with a kind's base state applied
 * (`DecisionDefault`; the same read as `DecisionTables::stateOf()` in SQL). Plain reads; they never evaluate,
 * so a page that shows registrations writes nothing. The evaluation reads the records themselves.
 */
final class Decisions
{
    /** @param ?DecisionRules $rules the declarations; none: the registry the evaluation uses */
    public function __construct(
        private readonly DecisionQuery $query = new DecisionQuery(),
        private readonly ?DecisionRules $rules = null,
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * Every declared decision kind, in reading order: core first, then extensions, each as registered.
     *
     * @return list<DecisionKind>
     */
    public function kinds(): array
    {
        return array_map(
            static fn (DecisionRule $rule): DecisionKind => new DecisionKind(
                $rule->kind(),
                $rule->label(),
                $rule->states(),
                $rule->defaultState(),
            ),
            ($this->rules ?? DecisionRules::current())->all()
        );
    }

    /** The declared kind, or null when nothing declares it. */
    public function kind(string $kind): ?DecisionKind
    {
        foreach ($this->kinds() as $declared) {
            if ($declared->kind === $kind) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * The registration's current decisions, with each base state applied: one statement for the records, and
     * one for the registration when a declared kind has a base state.
     *
     * @return array<string, CurrentDecision> by kind
     */
    public function currentOf(RegistrationKey $registration): array
    {
        $current = [];
        foreach ($this->query->currentOf($registration) as $kind => $decision) {
            $current[$kind] = new CurrentDecision(
                $decision->kind,
                $decision->state,
                $decision->effectiveAt,
                $decision->cause,
                $decision->recordedAt
            );
        }
        $defaults = $this->defaults();
        if ($defaults === []) {
            return $current;
        }
        $read = $this->registrations->find($registration);
        foreach ($defaults as $kind => $default) {
            if ($read === null || !$default->holdsFor($read->status)) {
                unset($current[$kind]);
            } elseif (!isset($current[$kind]) && $read->registeredAt !== null) {
                $current[$kind] = new CurrentDecision($kind, $default->state, $read->registeredAt);
            }
        }

        return $current;
    }

    /**
     * The current state of each listed kind for each listed registration of one event, in one statement, with
     * each base state applied over the given statuses.
     *
     * @param array<string, RegistrationStatus> $statuses the registrations' statuses, by code
     * @param list<string> $kinds
     * @return array<string, array<string, string>> the states by code and kind; no state, no entry
     */
    public function currentStates(int $eventId, array $statuses, array $kinds): array
    {
        $codes = array_map('strval', array_keys($statuses));
        $states = $this->query->currentStates($eventId, $codes, $kinds);
        foreach (array_intersect_key($this->defaults(), array_flip($kinds)) as $kind => $default) {
            foreach ($statuses as $code => $status) {
                if (!$default->holdsFor($status)) {
                    unset($states[$code][$kind]);
                } elseif (!isset($states[$code][$kind])) {
                    $states[$code][$kind] = $default->state;
                }
            }
        }

        return array_filter($states, static fn (array $byKind): bool => $byKind !== []);
    }

    /**
     * Every decision kind recorded in an event, and every kind with a base state (it holds where nothing is
     * recorded), in byte order. [UNAPPROVED N7]
     *
     * @return list<string>
     */
    public function kindsIn(int $eventId): array
    {
        $kinds = array_values(array_unique([...$this->query->kindsIn($eventId), ...array_keys($this->defaults())]));
        sort($kinds, SORT_STRING);

        return $kinds;
    }

    /**
     * The distinct states recorded for a kind in an event, and its base state, in byte order. [UNAPPROVED N7]
     *
     * @return list<string>
     */
    public function statesIn(int $eventId, string $kind): array
    {
        $states = $this->query->statesIn($eventId, $kind);
        $default = $this->defaults()[$kind] ?? null;
        if ($default !== null && !in_array($default->state, $states, true)) {
            $states[] = $default->state;
            sort($states, SORT_STRING);
        }

        return $states;
    }

    /** @return array<string, DecisionDefault> the declared base states, by kind */
    private function defaults(): array
    {
        $defaults = [];
        foreach ($this->kinds() as $kind) {
            if ($kind->default !== null) {
                $defaults[$kind->kind] = $kind->default;
            }
        }

        return $defaults;
    }
}
