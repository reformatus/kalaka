<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Serials;

/**
 * The view query: the admin side's one read path for registration data (foundation.md, "Views and
 * grants"). It reads only through a `HeldView`, which `Access` hands out after the access check. It reads
 * other modules' tables by name, only to read them, always `event_id` first, and never the stored
 * secret. The filter terms are correlated subqueries on the tables' event-first keys.
 *
 * [UNAPPROVED S36-6] Sort by one listed column (default: the sorszám order), paged by limit and offset
 * with a total. [UNAPPROVED S36-7] No caller builds a filter itself.
 */
final class ViewQuery extends Query
{
    /** The most rows one page may ask for. */
    public const MAX_LIMIT = 500;

    public function __construct(private readonly Serials $serials = new Serials())
    {
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

    /** How many registrations the view's filter matches now: one statement. [UNAPPROVED S36-9] */
    public function count(HeldView $view): int
    {
        [$conditions, $args] = $this->conditions($view->shape(), $view->bound());

        return $this->scope(function () use ($view, $conditions, $args): int {
            $rows = $this->select(
                'count',
                'SELECT COUNT(*) AS found FROM %i r WHERE r.event_id = %d' . $conditions,
                [$this->table('kalaka_registrations'), $view->eventId, ...$args]
            );

            return (int) ($rows[0]['found'] ?? 0);
        });
    }

    /**
     * One page of the registrations the view's filter matches, each with the view's columns: the stored
     * status, the sorszám, the stored field values and the latest state of each listed decision kind
     * (null where there is none).
     *
     * The order is by `$sort`, a listed column other than `code` (null: the sorszám order, which is
     * `registered_at` for `registered` and `created_at` for every other status), then by the sorszám
     * order and the code. Rows without a value sort first ascending.
     *
     * @throws InvalidInput when `$sort` is not a listed column, or the limit or offset is out of range
     */
    public function page(
        HeldView $view,
        ?string $sort = null,
        bool $descending = false,
        int $limit = 50,
        int $offset = 0,
    ): ViewPage {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new InvalidInput();
        }
        $shape = $this->shapeOf($view);
        if ($sort !== null && ($sort === 'code' || !$shape->lists($sort))) {
            throw new InvalidInput();
        }
        [$conditions, $conditionArgs] = $this->conditions($view->shape(), $view->bound());
        $eventId = $view->eventId;
        $direction = $descending ? ' DESC' : ' ASC';
        $orderingTime = "CASE WHEN r.status = 'registered' THEN r.registered_at ELSE r.created_at END";

        /** @var literal-string $order */
        $order = $orderingTime . $direction . ', r.code' . $direction;
        $sortArgs = [];
        if ($sort === 'status') {
            $order = 'r.status' . $direction . ', ' . $orderingTime . ', r.code';
        } elseif ($sort !== null && str_starts_with($sort, 'field:')) {
            $order = '(SELECT s.value FROM %i s WHERE s.event_id = r.event_id AND s.code = r.code'
                . ' AND s.field_key = %s)' . $direction . ', ' . $orderingTime . ', r.code';
            $sortArgs = [$this->table('kalaka_field_values'), substr($sort, 6)];
        } elseif ($sort !== null && str_starts_with($sort, 'decision:')) {
            $order = self::LATEST_STATE_SORT . $direction . ', ' . $orderingTime . ', r.code';
            $sortArgs = [$this->table('kalaka_decisions'), $this->table('kalaka_decisions'), substr($sort, 9)];
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
                [$this->table('kalaka_registrations'), $eventId, ...$conditionArgs, ...$sortArgs, $limit, $offset]
            );
            $codes = array_map(static fn (array $row): string => (string) $row['code'], $registrations);

            $fields = $this->fieldValues($eventId, $codes, $shape->fieldKeys());
            $decisions = $this->latestStates($eventId, $codes, $shape->decisionKinds());
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
                [$this->table('kalaka_registrations'), $eventId, ...$codes, ...$conditionArgs]
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
                [$this->table('kalaka_field_values'), $eventId]
            );
            $kinds = $this->select(
                'decision_kind',
                'SELECT DISTINCT kind FROM %i WHERE event_id = %d',
                [$this->table('kalaka_decisions'), $eventId]
            );

            $keys = array_map(static fn (array $row): string => (string) $row['field_key'], $keys);
            $kinds = array_map(static fn (array $row): string => (string) $row['kind'], $kinds);
            sort($keys, SORT_STRING);
            sort($kinds, SORT_STRING);

            return ViewShape::master($keys, $kinds);
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
            [$this->table('kalaka_field_values'), $eventId, ...$codes, ...$fieldKeys]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['code']][(string) $row['field_key']] = $row['value'];
        }

        return $values;
    }

    /**
     * @param list<string> $codes
     * @param list<string> $kinds
     * @return array<string, array<string, ?string>> the state of the latest record by code and kind
     */
    private function latestStates(int $eventId, array $codes, array $kinds): array
    {
        if ($codes === [] || $kinds === []) {
            return [];
        }

        $rows = $this->select(
            'decision',
            'SELECT d.code, d.kind, d.state FROM %i d WHERE d.event_id = %d AND d.code IN ('
            . implode(', ', array_fill(0, count($codes), '%s')) . ') AND d.kind IN ('
            . implode(', ', array_fill(0, count($kinds), '%s'))
            . ') AND d.id = (SELECT MAX(m.id) FROM %i m'
            . ' WHERE m.event_id = d.event_id AND m.code = d.code AND m.kind = d.kind)',
            [$this->table('kalaka_decisions'), $eventId, ...$codes, ...$kinds, $this->table('kalaka_decisions')]
        );

        $states = [];
        foreach ($rows as $row) {
            $states[(string) $row['code']][(string) $row['kind']] = $row['state'];
        }

        return $states;
    }

    /**
     * The state of the latest decision of one kind for the row `r`, as a subquery taking the decisions
     * table twice and the kind as arguments: aliases `d`/`m` for the filter, `s`/`n` for the sort.
     */
    private const LATEST_STATE_FILTER = '(SELECT d.state FROM %i d WHERE d.event_id = r.event_id AND d.id = ('
        . 'SELECT MAX(m.id) FROM %i m WHERE m.event_id = r.event_id AND m.code = r.code AND m.kind = %s))';

    private const LATEST_STATE_SORT = '(SELECT s.state FROM %i s WHERE s.event_id = r.event_id AND s.id = ('
        . 'SELECT MAX(n.id) FROM %i n WHERE n.event_id = r.event_id AND n.code = r.code AND n.kind = %s))';

    /**
     * The filter terms as `AND …` conditions on the alias `r` of `kalaka_registrations`, and their
     * arguments in placeholder order. A parameter term is matched as if `in` held the bound value alone.
     * Fail closed: a parameter without a bound value, or a bound value without a parameter, matches
     * nothing (S36-4).
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function conditions(?ViewShape $shape, ?string $bound): array
    {
        if ($shape === null) {
            return $bound === null ? ['', []] : [' AND 1 = 0', []];
        }
        if ($shape->hasParameter() !== ($bound !== null)) {
            return [' AND 1 = 0', []];
        }

        /** @var list<literal-string> $fragments */
        $fragments = [];
        $args = [];
        foreach ($shape->filter as $term) {
            $column = $term['column'];
            $values = $term['in'] ?? [(string) $bound];
            $in = implode(', ', array_fill(0, count($values), '%s'));

            if ($column === 'status') {
                $fragments[] = ' AND r.status IN (' . $in . ')';
                array_push($args, ...$values);
            } elseif (str_starts_with($column, 'field:')) {
                $fragments[] = ' AND EXISTS (SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code'
                    . ' AND f.field_key = %s AND f.value IN (' . $in . '))';
                array_push($args, $this->table('kalaka_field_values'), substr($column, 6), ...$values);
            } else {
                $fragments[] = ' AND ' . self::LATEST_STATE_FILTER . ' IN (' . $in . ')';
                array_push(
                    $args,
                    $this->table('kalaka_decisions'),
                    $this->table('kalaka_decisions'),
                    substr($column, 9),
                    ...$values
                );
            }
        }

        return [implode('', $fragments), $args];
    }
}
