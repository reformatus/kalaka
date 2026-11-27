<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Decision\DecisionTables;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationTables;
use Kalaka\Registrations\Api\Serials;

/**
 * The view query: the admin side's one read path for registration data (foundation.md, "Views and
 * grants"). It reads only through a `HeldView`, which `Access` hands out after the access check. It reads
 * other modules' tables by name, only to read them, always `event_id` first, and never the stored
 * secret. The filter terms are correlated subqueries on the tables' event-first keys.
 *
 * Sort by one listed column (default: the sorszám order), paged by limit and offset
 * with a total. No caller builds a filter itself.
 */
final class ViewQuery extends Query
{
    /** The most rows one page may ask for. */
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly Serials $serials = new Serials(),
        private readonly Decisions $decisions = new Decisions(),
    ) {
        parent::__construct();
    }

    /**
     * The view's columns: the stored ones, or for the master view `code`, `status`, `serial`, every field
     * key stored in the event (read-write) and every decision kind recorded in it, in byte order.
     *
     * @return list<array{column: string, write: bool}>
     */
    public function columns(HeldView $view): array
    {
        return $this->shapeOf($view)->columns;
    }

    /** How many registrations the view's filter matches now: one statement. */
    public function count(HeldView $view): int
    {
        [$conditions, $args] = $this->conditions($view->shape(), $view->bound());

        return $this->scope(function () use ($view, $conditions, $args): int {
            $rows = $this->select(
                'count',
                'SELECT COUNT(*) AS found FROM %i r WHERE r.event_id = %d' . $conditions,
                [$this->table(RegistrationTables::REGISTRATIONS), $view->eventId, ...$args]
            );

            return (int) ($rows[0]['found'] ?? 0);
        });
    }

    /**
     * One page of the registrations the view's filter matches, each with the view's columns: the stored
     * status, the sorszám, the stored field values and the latest state of each listed decision kind
     * (null where there is none).
     *
     * The order is by `$sort`, a listed column (`code`, or null: the sorszám order, which is the ordering
     * time, `registered_at` for `registered` and `created_at` for every other status, then the code), then
     * by the sorszám order and the code. `code` is the default order itself (S37, topic 6a: Kód carries it),
     * so descending is its exact reverse. Rows without a value sort first ascending.
     *
     * A column in `$numeric` (the grid's number-valued fields) sorts by its number, not by the stored
     * text: the value is cast to a decimal, with an empty value treated as missing. Empty and missing
     * values therefore both sort first ascending and last descending, as they do for text.
     *
     * @param list<string> $numeric the columns to order by number
     * @throws InvalidInput when `$sort` is not a listed column, or the limit or offset is out of range
     */
    public function page(
        HeldView $view,
        ?string $sort = null,
        bool $descending = false,
        int $limit = 50,
        int $offset = 0,
        array $numeric = [],
    ): ViewPage {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new InvalidInput();
        }
        $shape = $this->shapeOf($view);
        if ($sort !== null && !$shape->lists($sort)) {
            throw new InvalidInput();
        }
        if ($sort === 'code') {
            $sort = null; // the code column is the default order, in either direction
        }
        [$conditions, $conditionArgs] = $this->conditions($view->shape(), $view->bound());
        $eventId = $view->eventId;
        $direction = $descending ? ' DESC' : ' ASC';
        // The sorszám order is Registrations' (RegistrationTables), so this grid and the sorszám cannot drift.
        $serialOrder = RegistrationTables::serialOrder('r');

        /** @var literal-string $order */
        $order = RegistrationTables::serialOrder('r', $descending);
        $sortArgs = [];
        if ($sort === 'status') {
            $order = 'r.status' . $direction . ', ' . $serialOrder;
        } elseif ($sort !== null && str_starts_with($sort, 'field:')) {
            $value = '(SELECT s.value FROM %i s WHERE s.event_id = r.event_id AND s.code = r.code'
                . ' AND s.field_key = %s)';
            $order = (in_array($sort, $numeric, true)
                ? 'CAST(NULLIF(' . $value . ", '') AS DECIMAL(20,4))"
                : $value) . $direction . ', ' . $serialOrder;
            $sortArgs = [$this->table(RegistrationTables::FIELD_VALUES), substr($sort, 6)];
        } elseif ($sort !== null && str_starts_with($sort, 'decision:')) {
            $order = DecisionTables::currentState('r', 's', 'n') . $direction . ', ' . $serialOrder;
            $sortArgs = [
                $this->table(DecisionTables::DECISIONS),
                $this->table(DecisionTables::DECISIONS),
                substr($sort, 9),
            ];
        }

        $total = $this->count($view);

        return $this->scope(function () use (
            $eventId,
            $shape,
            $conditions,
            $conditionArgs,
            $order,
            $sortArgs,
            $limit,
            $offset,
            $total,
        ): ViewPage {
            $registrations = $this->select(
                'registration',
                'SELECT r.code, r.status FROM %i r WHERE r.event_id = %d' . $conditions
                . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d',
                [
                    $this->table(RegistrationTables::REGISTRATIONS),
                    $eventId,
                    ...$conditionArgs,
                    ...$sortArgs,
                    $limit,
                    $offset,
                ]
            );
            $codes = array_map(static fn (array $row): string => (string) $row['code'], $registrations);

            $fields = $this->fieldValues($eventId, $codes, $shape->fieldKeys());
            $decisions = $this->decisions->currentStates($eventId, $codes, $shape->decisionKinds());
            $serials = $shape->lists('serial') && $codes !== [] ? $this->serials->forEvent($eventId) : [];

            $rows = [];
            foreach ($registrations as $registration) {
                $code = (string) $registration['code'];
                $values = [];
                foreach ($shape->columns as $listed) {
                    $column = $listed['column'];
                    $values[$column] = match (true) {
                        $column === 'code' => $code,
                        $column === 'status' => (string) $registration['status'],
                        $column === 'serial' => $serials[$code] ?? null,
                        str_starts_with($column, 'field:') => $fields[$code][substr($column, 6)] ?? null,
                        default => $decisions[$code][substr($column, 9)] ?? null,
                    };
                }
                $rows[] = new ViewRow(new RegistrationKey($eventId, $code), $values);
            }

            return new ViewPage($shape->columns, $rows, $total);
        });
    }

    /**
     * Aggregates over every active registration the view's filter matches now, never one page (S37, the
     * grid's sum row): one statement for all the specs, on the event's rows. Only a registered
     * registration counts: waitlisted, withdrawn and removed rows are dimmed and never count in the sum
     * row (owner, 2026-11-27). A grant holder's aggregates cover only their view (S36-9). By spec index: an
     * integer for the counts, a decimal string (trailing zeros cut) or null for `sum` and `average`. Choice
     * values compare with the column's collation, as the filter does; a multiple choice (a JSON list)
     * counts when it holds one of the members.
     *
     * @param list<AggregateSpec> $specs
     * @return list<int|string|null>
     * @throws InvalidInput when a spec's column is not listed in the view
     */
    public function aggregates(HeldView $view, array $specs): array
    {
        if ($specs === []) {
            return [];
        }
        $shape = $this->shapeOf($view);
        foreach ($specs as $spec) {
            if (!$shape->lists($spec->column)) {
                throw new InvalidInput();
            }
        }
        [$conditions, $conditionArgs] = $this->conditions($view->shape(), $view->bound());
        $values = $this->table(RegistrationTables::FIELD_VALUES);
        $decisions = $this->table(DecisionTables::DECISIONS);

        /** @var list<literal-string> $selects */
        $selects = [];
        $args = [];
        foreach ($specs as $index => $spec) {
            [$expression, $expressionArgs] = self::aggregate($spec, $values, $decisions);
            $selects[] = $expression . ' AS %i';
            array_push($args, ...$expressionArgs);
            $args[] = 'a' . $index;
        }

        $eventId = $view->eventId;
        $active = RegistrationStatus::Registered->value;
        $rows = $this->scope(fn (): array => $this->select(
            'aggregate',
            'SELECT ' . implode(', ', $selects) . ' FROM %i r WHERE r.event_id = %d AND r.status = %s'
                . $conditions,
            [
                ...$args,
                $this->table(RegistrationTables::REGISTRATIONS),
                $eventId,
                $active,
                ...$conditionArgs,
            ]
        ));
        $row = $rows[0] ?? [];

        $results = [];
        foreach ($specs as $index => $spec) {
            $value = $row['a' . $index] ?? null;
            $results[] = match ($spec->kind) {
                AggregateKind::Sum, AggregateKind::Average => $value === null ? null : self::decimal((string) $value),
                default => (int) $value,
            };
        }

        return $results;
    }

    /**
     * Whether every given registration is in the view's event and inside its filter now: one statement,
     * none for an empty list. For the access checks.
     *
     * @param list<RegistrationKey> $keys
     */
    public function contains(HeldView $view, array $keys): bool
    {
        return $this->filterHolds($view->eventId, $view->shape(), $view->bound(), $keys);
    }

    /**
     * @internal For `AdminAccess`, before a `HeldView` exists: whether the filter of the shape (null: the
     * master view) with the bound value holds every given registration of the event.
     *
     * @param list<RegistrationKey> $keys
     */
    public function filterHolds(int $eventId, ?ViewShape $shape, ?string $bound, array $keys): bool
    {
        [$conditions, $conditionArgs] = $this->conditions($shape, $bound);

        $codes = [];
        foreach ($keys as $key) {
            if ($key->eventId !== $eventId) {
                return false;
            }
            $codes[$key->code] = true;
        }
        $codes = array_map('strval', array_keys($codes));
        if ($codes === []) {
            return true;
        }

        return $this->scope(function () use ($eventId, $codes, $conditions, $conditionArgs): bool {
            $rows = $this->select(
                'count',
                'SELECT COUNT(*) AS found FROM %i r WHERE r.event_id = %d AND r.code IN ('
                . implode(', ', array_fill(0, count($codes), '%s')) . ')' . $conditions,
                [$this->table(RegistrationTables::REGISTRATIONS), $eventId, ...$codes, ...$conditionArgs]
            );

            return (int) ($rows[0]['found'] ?? 0) === count($codes);
        });
    }

    private function shapeOf(HeldView $view): ViewShape
    {
        return $view->shape() ?? $this->masterShape($view->eventId);
    }

    /** The master view of an event: every field key stored in it and every decision kind recorded in it. */
    private function masterShape(int $eventId): ViewShape
    {
        return $this->scope(function () use ($eventId): ViewShape {
            $keys = $this->select(
                'field_key',
                'SELECT DISTINCT field_key FROM %i WHERE event_id = %d',
                [$this->table(RegistrationTables::FIELD_VALUES), $eventId]
            );

            $keys = array_map(static fn (array $row): string => (string) $row['field_key'], $keys);
            sort($keys, SORT_STRING);

            return ViewShape::master($keys, $this->decisions->kindsIn($eventId));
        });
    }

    /**
     * @param list<string> $codes
     * @param list<string> $fieldKeys
     * @return array<string, array<string, ?string>> the values by code and field key
     */
    private function fieldValues(int $eventId, array $codes, array $fieldKeys): array
    {
        if ($codes === [] || $fieldKeys === []) {
            return [];
        }

        $rows = $this->select(
            'field_value',
            'SELECT code, field_key, value FROM %i WHERE event_id = %d AND code IN ('
            . implode(', ', array_fill(0, count($codes), '%s')) . ') AND field_key IN ('
            . implode(', ', array_fill(0, count($fieldKeys), '%s')) . ')',
            [$this->table(RegistrationTables::FIELD_VALUES), $eventId, ...$codes, ...$fieldKeys]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['code']][(string) $row['field_key']] = $row['value'];
        }

        return $values;
    }

    /**
     * One aggregate as a select expression over the alias `r`, with its arguments in placeholder order.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private static function aggregate(AggregateSpec $spec, string $values, string $decisions): array
    {
        $field = 'SELECT 1 FROM %i s WHERE s.event_id = r.event_id AND s.code = r.code AND s.field_key = %s';
        $number = '(SELECT CAST(s.value AS DECIMAL(26,8)) FROM %i s WHERE s.event_id = r.event_id'
            . " AND s.code = r.code AND s.field_key = %s AND s.value <> '')";
        $key = substr($spec->column, 6);
        $in = implode(', ', array_fill(0, count($spec->members), '%s'));

        switch ($spec->kind) {
            case AggregateKind::Rows:
                return ['COUNT(*)', []];
            case AggregateKind::Yes:
                return ['COALESCE(SUM(EXISTS(' . $field . " AND s.value = '1')), 0)", [$values, $key]];
            case AggregateKind::Filled:
                return ['COALESCE(SUM(EXISTS(' . $field . " AND s.value <> '')), 0)", [$values, $key]];
            case AggregateKind::Sum:
                return ['SUM(' . $number . ')', [$values, $key]];
            case AggregateKind::Average:
                return ['AVG(' . $number . ')', [$values, $key]];
        }

        if ($spec->column === 'status') {
            return ['COALESCE(SUM(r.status IN (' . $in . ')), 0)', $spec->members];
        }
        if (str_starts_with($spec->column, 'decision:')) {
            return [
                'COALESCE(SUM(' . DecisionTables::currentState('r', 'd', 'm') . ' IN (' . $in . ')), 0)',
                [$decisions, $decisions, substr($spec->column, 9), ...$spec->members],
            ];
        }

        // A single choice stores the value; a multiple choice a JSON list of values.
        /** @var literal-string $contains */
        $contains = implode(' OR ', array_fill(0, count($spec->members), 'JSON_CONTAINS(s.value, JSON_QUOTE(%s))'));

        return [
            'COALESCE(SUM(EXISTS(' . $field . ' AND (s.value IN (' . $in . ")"
            . " OR (LEFT(s.value, 1) = '[' AND JSON_VALID(s.value) AND (" . $contains . '))))), 0)',
            [$values, $key, ...$spec->members, ...$spec->members],
        ];
    }

    /** A decimal string without trailing zeros: `183.00000000` → `183`, `4.50000000` → `4.5`. */
    private static function decimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-' ? '0' : $value;
    }

    /**
     * The filter terms as `AND …` conditions on the alias `r` of `kalaka_registrations`, and their
     * arguments in placeholder order. A parameter term is matched as if `in` held the bound value alone.
     * S36-4 (owner ruling 2026-11-16, (c)): the parameter term applies only with a bound value, so a grant
     * without one (its view's parameter was added, moved or removed after it was given) sees the whole
     * view; a bound value with no parameter term to apply to is ignored the same way.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function conditions(?ViewShape $shape, ?string $bound): array
    {
        if ($shape === null) {
            return ['', []];
        }

        /** @var list<literal-string> $fragments */
        $fragments = [];
        $args = [];
        foreach ($shape->filter as $term) {
            if ($term['in'] === null && $bound === null) {
                continue;
            }
            $column = $term['column'];
            $values = $term['in'] ?? [(string) $bound];
            $in = implode(', ', array_fill(0, count($values), '%s'));

            if ($column === 'status') {
                $fragments[] = ' AND r.status IN (' . $in . ')';
                array_push($args, ...$values);
            } elseif (str_starts_with($column, 'field:')) {
                $fragments[] = ' AND EXISTS (SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code'
                    . ' AND f.field_key = %s AND f.value IN (' . $in . '))';
                array_push($args, $this->table(RegistrationTables::FIELD_VALUES), substr($column, 6), ...$values);
            } else {
                $fragments[] = ' AND ' . DecisionTables::currentState('r', 'd', 'm') . ' IN (' . $in . ')';
                array_push(
                    $args,
                    $this->table(DecisionTables::DECISIONS),
                    $this->table(DecisionTables::DECISIONS),
                    substr($column, 9),
                    ...$values
                );
            }
        }

        return [implode('', $fragments), $args];
    }
}
