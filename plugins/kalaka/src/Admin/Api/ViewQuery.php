<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Decision\DecisionTables;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationKey;
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
        // The sorszám order is Registrations' (RegistrationTables), so this grid and the sorszám cannot drift.
        $serialOrder = RegistrationTables::serialOrder('r');

        /** @var literal-string $order */
        $order = RegistrationTables::serialOrder('r', $descending);
        $sortArgs = [];
        if ($sort === 'status') {
            $order = 'r.status' . $direction . ', ' . $serialOrder;
        } elseif ($sort !== null && str_starts_with($sort, 'field:')) {
            $order = '(SELECT s.value FROM %i s WHERE s.event_id = r.event_id AND s.code = r.code'
                . ' AND s.field_key = %s)' . $direction . ', ' . $serialOrder;
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
     * One registration as the views that hold it show it, the union of their columns. The views must be the
     * ones `Access::holding()` found to hold it (or the master view of full access): this read does not
     * check the filter again. Null when the event has no such registration. The times of `registered_at` and
     * the first submission are shown to the master view only, as no column lists them.
     *
     * @param non-empty-list<HeldView> $views
     */
    public function registration(array $views, string $code): ?ViewRegistration
    {
        $eventId = $views[0]->eventId;
        $full = false;
        $columns = [];
        foreach ($views as $view) {
            $shape = $view->shape();
            if ($view->eventId !== $eventId) {
                throw new InvalidInput();
            }
            if ($shape === null) {
                $full = true;
                continue;
            }
            foreach ($shape->columns as $listed) {
                $columns[$listed['column']] = true;
            }
        }
        $columns = $full ? [] : array_map('strval', array_keys($columns));

        $key = new RegistrationKey($eventId, $code);
        $row = $this->scope(function () use ($key): ?array {
            $rows = $this->select(
                'registration',
                'SELECT status, registered_at FROM %i WHERE event_id = %d AND code = %s',
                [$this->table(RegistrationTables::REGISTRATIONS), $key->eventId, $key->code]
            );

            return $rows[0] ?? null;
        });
        if ($row === null) {
            return null;
        }
        $lists = static fn (string $column): bool => $full || in_array($column, $columns, true);

        $fieldKeys = [];
        $decisionKinds = [];
        foreach ($columns as $column) {
            if (str_starts_with($column, 'field:')) {
                $fieldKeys[] = substr($column, 6);
            } elseif (str_starts_with($column, 'decision:')) {
                $decisionKinds[] = substr($column, 9);
            }
        }

        $fields = $this->scope(
            fn (): array => $this->detailValues($key, $full ? null : $fieldKeys)
        );
        $decisions = [];
        if ($full || $decisionKinds !== []) {
            foreach ($this->decisions->currentOf($key) as $kind => $decision) {
                if ($full || in_array($kind, $decisionKinds, true)) {
                    $decisions[$kind] = $decision;
                }
            }
        }
        $first = $this->scope(function () use ($key): ?array {
            $rows = $this->select(
                'submission',
                'SELECT form_id, form_version, submitted_at FROM %i WHERE event_id = %d AND code = %s AND number = 1',
                [$this->table(RegistrationTables::SUBMISSIONS), $key->eventId, $key->code]
            );

            return $rows[0] ?? null;
        });
        $serial = $lists('serial') ? ($this->serials->forEvent($eventId)[$code] ?? null) : null;

        return new ViewRegistration(
            $key,
            $full,
            $columns,
            $lists('status') ? RegistrationStatus::from((string) $row['status']) : null,
            $serial,
            $full && $row['registered_at'] !== null ? Instant::fromStorage((string) $row['registered_at']) : null,
            $full && $first !== null ? Instant::fromStorage((string) $first['submitted_at']) : null,
            $fields,
            $decisions,
            $first === null
                ? null
                : ['form_id' => (int) $first['form_id'], 'form_version' => (int) $first['form_version']],
        );
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
     * The stored values of one registration: the listed keys, or every key when `$fieldKeys` is null.
     *
     * @param ?list<string> $fieldKeys
     * @return array<string, ?string> by field key
     */
    private function detailValues(RegistrationKey $key, ?array $fieldKeys): array
    {
        if ($fieldKeys === []) {
            return [];
        }
        $in = $fieldKeys === null
            ? ''
            : ' AND field_key IN (' . implode(', ', array_fill(0, count($fieldKeys), '%s')) . ')';
        $rows = $this->select(
            'field_value',
            'SELECT field_key, value FROM %i WHERE event_id = %d AND code = %s' . $in,
            [$this->table(RegistrationTables::FIELD_VALUES), $key->eventId, $key->code, ...($fieldKeys ?? [])]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['field_key']] = $row['value'];
        }

        return $values;
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
