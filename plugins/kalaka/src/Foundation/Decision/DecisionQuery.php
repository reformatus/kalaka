<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Decision\Decision;
use Kalaka\Foundation\Api\Decision\DecisionTables;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the decisions of one registration, or the current states of a page of an event's registrations. */
final class DecisionQuery extends Query
{
    /**
     * One statement over the registration's rows; the last row of a kind is its current decision.
     *
     * @return array<string, Decision> by kind
     */
    public function currentOf(RegistrationKey $registration): array
    {
        return $this->scope(function () use ($registration): array {
            $rows = $this->select(
                'decision',
                'SELECT * FROM %i WHERE event_id = %d AND code = %s ORDER BY id',
                [$this->table(DecisionTables::DECISIONS), $registration->eventId, $registration->code]
            );

            $current = [];
            foreach ($rows as $row) {
                $kind = (string) $row['kind'];
                $current[$kind] = new Decision(
                    $kind,
                    (string) $row['state'],
                    Instant::fromStorage((string) $row['effective_at']),
                    Instant::fromStorage((string) $row['recorded_at']),
                    new Subject(
                        (string) $row['cause_type'],
                        $row['cause_event_id'] === null ? null : (int) $row['cause_event_id'],
                        (string) $row['cause_key'],
                    ),
                );
            }

            return $current;
        });
    }

    /**
     * The current state of each listed kind for each listed code of one event, in one statement: the
     * latest record of a kind, as `currentOf()` and `DecisionTables::currentState()` read it.
     *
     * @param list<string> $codes
     * @param list<string> $kinds
     * @return array<string, array<string, string>> the states by code and kind; no record, no entry
     */
    public function currentStates(int $eventId, array $codes, array $kinds): array
    {
        if ($codes === [] || $kinds === []) {
            return [];
        }

        return $this->scope(function () use ($eventId, $codes, $kinds): array {
            $rows = $this->select(
                'decision',
                'SELECT d.code, d.kind, d.state FROM %i d WHERE d.event_id = %d AND d.code IN ('
                . implode(', ', array_fill(0, count($codes), '%s')) . ') AND d.kind IN ('
                . implode(', ', array_fill(0, count($kinds), '%s'))
                . ') AND d.id = (SELECT MAX(m.id) FROM %i m'
                . ' WHERE m.event_id = d.event_id AND m.code = d.code AND m.kind = d.kind)',
                [
                    $this->table(DecisionTables::DECISIONS),
                    $eventId,
                    ...$codes,
                    ...$kinds,
                    $this->table(DecisionTables::DECISIONS),
                ]
            );

            $states = [];
            foreach ($rows as $row) {
                $states[(string) $row['code']][(string) $row['kind']] = (string) $row['state'];
            }

            return $states;
        });
    }

    /**
     * Every decision kind recorded in an event, in byte order.
     *
     * @return list<string>
     */
    public function kindsIn(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'decision_kind',
                'SELECT DISTINCT kind FROM %i WHERE event_id = %d',
                [$this->table(DecisionTables::DECISIONS), $eventId]
            );

            $kinds = array_map(static fn (array $row): string => (string) $row['kind'], $rows);
            sort($kinds, SORT_STRING);

            return $kinds;
        });
    }

    /**
     * The distinct states recorded for one kind in an event, in byte order.
     *
     * @return list<string>
     */
    public function statesIn(int $eventId, string $kind): array
    {
        return $this->scope(function () use ($eventId, $kind): array {
            $rows = $this->select(
                'decision_state',
                'SELECT DISTINCT state FROM %i WHERE event_id = %d AND kind = %s',
                [$this->table(DecisionTables::DECISIONS), $eventId, $kind]
            );

            $states = array_map(static fn (array $row): string => (string) $row['state'], $rows);
            sort($states, SORT_STRING);

            return $states;
        });
    }
}
