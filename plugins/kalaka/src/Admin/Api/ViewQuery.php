<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Decision\DecisionTables;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Forms\Api\Forms;
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

    /** The most registration codes one call may ask the display names for. */
    public const MAX_NAMES = 100;

    /** The columns a view lists for display only: neither sortable nor filterable. */
    private const DISPLAY_ONLY = ['registered_at', 'submitted_at'];

    /** How the time columns show an instant: UTC, as the detail read does. */
    private const TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly Serials $serials = new Serials(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly Forms $forms = new Forms(),
    ) {
        parent::__construct();
    }

    /**
     * The view's columns: the stored ones, or for the master view `code`, `status`, `serial`, every field
     * key stored in the event (read-write) and every decision kind recorded in it or with a base state, in byte
     * order.
     *
     * @return list<array{column: string, write: bool}>
     */
    public function columns(HeldView $view): array
    {
        return $this->shapeOf($view)->columns;
    }

    /**
     * How many registrations the view's filter matches now (narrowed by the grid's ad hoc filters and search
     * when given): one statement.
     *
     * @throws InvalidInput when a narrowing filter names a column the view does not list
     */
    public function count(HeldView $view, ?Narrowing $narrowing = null): int
    {
        [$conditions, $args] = $this->conditions($view->shape(), $view->bound());
        [$narrow, $narrowArgs] = $this->narrowed($view, $narrowing);
        $conditions .= $narrow;
        array_push($args, ...$narrowArgs);

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
     * The codes of every registration the view's filter matches, narrowed by the grid's ad hoc filters and search
     * when given: all of them, with no paging limit, in the default order (the sorszám order, then the code). The
     * very conditions of `count()` and `page()`, so a bulk act on "everything the grid shows" reaches the rows
     * the grid's pages add up to and no others. One statement.
     *
     * @return list<string>
     * @throws InvalidInput when a narrowing filter names a column the view does not list
     */
    public function codesOf(HeldView $view, ?Narrowing $narrowing = null): array
    {
        [$conditions, $args] = $this->conditions($view->shape(), $view->bound());
        [$narrow, $narrowArgs] = $this->narrowed($view, $narrowing);
        $conditions .= $narrow;
        array_push($args, ...$narrowArgs);

        return $this->scope(function () use ($view, $conditions, $args): array {
            $rows = $this->select(
                'registration',
                'SELECT r.code FROM %i r WHERE r.event_id = %d' . $conditions
                . ' ORDER BY ' . RegistrationTables::serialOrder('r'),
                [$this->table(RegistrationTables::REGISTRATIONS), $view->eventId, ...$args]
            );

            return array_map(static fn (array $row): string => (string) $row['code'], $rows);
        });
    }

    /**
     * One page of the registrations the view's filter matches, each with the view's columns: the stored
     * status, the sorszám, the stored field values and the latest state of each listed decision kind
     * (null where there is none).
     *
     * `serial` (Szám) sorts by the computed sorszám as a number: it interleaves the statuses, a row with no
     * sorszám sorts first ascending and last descending, and ties fall back to the default order.
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
     * The grid's ad hoc filters and search (`$narrowing`) apply on top of the view's own filter and bound
     * value, so they only narrow; `total` counts the narrowed set.
     *
     * @param list<string> $numeric the columns to order by number
     * @throws InvalidInput when `$sort` is not a listed column, a narrowing filter names a column the view does
     *     not list, or the limit or offset is out of range
     */
    public function page(
        HeldView $view,
        ?string $sort = null,
        bool $descending = false,
        int $limit = 50,
        int $offset = 0,
        array $numeric = [],
        ?Narrowing $narrowing = null,
    ): ViewPage {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new InvalidInput();
        }
        $shape = $this->shapeOf($view);
        if ($sort !== null && (in_array($sort, self::DISPLAY_ONLY, true) || !$shape->lists($sort))) {
            throw new InvalidInput();
        }
        if ($sort === 'code') {
            $sort = null; // the code column is the default order, in either direction
        }
        [$conditions, $conditionArgs] = $this->conditions($view->shape(), $view->bound());
        [$narrow, $narrowArgs] = $this->narrowed($view, $narrowing);
        $conditions .= $narrow;
        array_push($conditionArgs, ...$narrowArgs);
        $eventId = $view->eventId;
        $direction = $descending ? ' DESC' : ' ASC';
        // The sorszám order is Registrations' (RegistrationTables), so this grid and the sorszám cannot drift.
        $serialOrder = RegistrationTables::serialOrder('r');

        /** @var literal-string $order */
        $order = RegistrationTables::serialOrder('r', $descending);
        $sortArgs = [];
        $join = '';
        $joinArgs = [];
        if ($sort === 'status') {
            $order = 'r.status' . $direction . ', ' . $serialOrder;
        } elseif ($sort === 'serial') {
            // The computed sorszám as a number; the rows with none (withdrawn, removed) sort first
            // ascending, and a tie (the same number in `registered` and `waitlisted`) falls back to the default order.
            [$ranked, $joinArgs] = RegistrationTables::serialNumbers(
                $this->table(RegistrationTables::REGISTRATIONS),
                $eventId
            );
            $join = ' LEFT JOIN (' . $ranked . ') n ON n.code = r.code';
            $order = 'n.serial_no' . $direction . ', ' . $serialOrder;
        } elseif ($sort !== null && str_starts_with($sort, 'field:')) {
            $value = '(SELECT s.value FROM %i s WHERE s.event_id = r.event_id AND s.code = r.code'
                . ' AND s.field_key = %s)';
            $order = (in_array($sort, $numeric, true)
                ? 'CAST(NULLIF(' . $value . ", '') AS DECIMAL(20,4))"
                : $value) . $direction . ', ' . $serialOrder;
            $sortArgs = [$this->table(RegistrationTables::FIELD_VALUES), substr($sort, 6)];
        } elseif ($sort !== null && str_starts_with($sort, 'decision:')) {
            [$state, $sortArgs] = $this->decisionState(substr($sort, 9), 's', 'n');
            $order = $state . $direction . ', ' . $serialOrder;
        }

        $total = $this->count($view, $narrowing);

        return $this->scope(function () use (
            $eventId,
            $shape,
            $conditions,
            $conditionArgs,
            $order,
            $sortArgs,
            $join,
            $joinArgs,
            $limit,
            $offset,
            $total,
        ): ViewPage {
            $registrations = $this->select(
                'registration',
                'SELECT r.code, r.status, r.registered_at FROM %i r' . $join . ' WHERE r.event_id = %d' . $conditions
                . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d',
                [
                    $this->table(RegistrationTables::REGISTRATIONS),
                    ...$joinArgs,
                    $eventId,
                    ...$conditionArgs,
                    ...$sortArgs,
                    $limit,
                    $offset,
                ]
            );
            $codes = array_map(static fn (array $row): string => (string) $row['code'], $registrations);

            $fields = $this->fieldValues($eventId, $codes, $shape->fieldKeys());
            $statuses = [];
            foreach ($registrations as $registration) {
                $statuses[(string) $registration['code']] = RegistrationStatus::from((string) $registration['status']);
            }
            $decisions = $this->decisions->currentStates($eventId, $statuses, $shape->decisionKinds());
            $serials = $shape->lists('serial') && $codes !== [] ? $this->serials->forEvent($eventId) : [];
            $submitted = $shape->lists('submitted_at') ? $this->firstSubmissionTimes($eventId, $codes) : [];

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
                        $column === 'registered_at' => $registration['registered_at'] === null
                            ? null
                            : Instant::fromStorage((string) $registration['registered_at'])
                                ->format(self::TIME_FORMAT),
                        $column === 'submitted_at' => $submitted[$code] ?? null,
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
     * The time of the first submission of each code, as `TIME_FORMAT` (one statement, inside the caller's query
     * scope); a code without one is left out.
     *
     * @param list<string> $codes
     * @return array<string, string>
     */
    private function firstSubmissionTimes(int $eventId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $this->select(
            'submission',
            'SELECT code, submitted_at FROM %i WHERE event_id = %d AND number = 1 AND code IN ('
            . implode(', ', array_fill(0, count($codes), '%s')) . ')',
            [$this->table(RegistrationTables::SUBMISSIONS), $eventId, ...$codes]
        );
        $times = [];
        foreach ($rows as $row) {
            $times[(string) $row['code']] = Instant::fromStorage((string) $row['submitted_at'])
                ->format(self::TIME_FORMAT);
        }

        return $times;
    }

    /**
     * One registration as the views that hold it show it, the union of their columns. The views must be the
     * ones `Access::holding()` found to hold it (or the master view of full access): this read does not
     * check the filter again. Null when the event has no such registration. The times of `registered_at` and
     * the first submission are shown only where a held view lists the column (`registered_at`, `submitted_at`),
     * and always to full access.
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
            $lists('registered_at') && $row['registered_at'] !== null
                ? Instant::fromStorage((string) $row['registered_at'])
                : null,
            $lists('submitted_at') && $first !== null ? Instant::fromStorage((string) $first['submitted_at']) : null,
            $fields,
            $decisions,
            $first === null
                ? null
                : ['form_id' => (int) $first['form_id'], 'form_version' => (int) $first['form_version']],
        );
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
     * With a narrowing (the grid's ad hoc filters and search) the aggregates cover the narrowed set.
     *
     * @param list<AggregateSpec> $specs
     * @return list<int|string|null>
     * @throws InvalidInput when a spec's or a narrowing filter's column is not listed in the view
     */
    public function aggregates(HeldView $view, array $specs, ?Narrowing $narrowing = null): array
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
        [$narrow, $narrowArgs] = $this->narrowed($view, $narrowing);
        $conditions .= $narrow;
        array_push($conditionArgs, ...$narrowArgs);
        $values = $this->table(RegistrationTables::FIELD_VALUES);

        /** @var list<literal-string> $selects */
        $selects = [];
        $args = [];
        foreach ($specs as $index => $spec) {
            [$expression, $expressionArgs] = $this->aggregate($spec, $values);
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

    /**
     * The display name of each given registration, as the view shows it: the value of the display-name field of
     * the slot form's version the registration was submitted with (the name field is resolved through `Forms`,
     * as the detail read does), or null when the view does not list that column (no name access), the code is
     * no registration of the event, the view's filter does not hold it, or the field has no answer. Full access
     * (the master view) sees every name. A name is personal data, so a code outside the view's filter is never
     * read or revealed.
     *
     * The reads are the view's own conditions on the given codes (`heldCodes()`), their slot forms, and the
     * name values; the name field is read through WordPress posts, outside the query's scope, so this read is
     * for the screens, never inside a use case's transaction.
     *
     * @param list<string> $codes at most `MAX_NAMES`, each once
     * @return array<string, ?string> the name by code, in the order the codes were given; null where there is none
     * @throws InvalidInput when more than `MAX_NAMES` codes are given
     */
    public function namesOf(HeldView $view, array $codes): array
    {
        if (count($codes) > self::MAX_NAMES) {
            throw new InvalidInput();
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        $eventId = $view->eventId;
        $shape = $view->shape();
        $full = $shape === null;

        $held = $this->scope(fn (): array => $this->heldCodes($view, $codes));
        $slotForms = $this->scope(fn (): array => $this->slotForms($eventId, $codes));

        // The display-name field key of each code's slot form, once per distinct version.
        $nameKeys = [];
        $resolved = [];
        foreach ($slotForms as $code => $slot) {
            $slotKey = $slot['form_id'] . ':' . $slot['form_version'];
            if (!array_key_exists($slotKey, $resolved)) {
                $resolved[$slotKey] = $this->forms->contactKeys($slot['form_id'], $slot['form_version'])['name'];
            }
            $nameKeys[$code] = $resolved[$slotKey];
        }

        // Read a value only for a code the view holds and whose name column the view lists.
        $readCodes = [];
        $wanted = [];
        foreach ($codes as $code) {
            $nameKey = $nameKeys[$code] ?? null;
            if (!isset($held[$code]) || $nameKey === null || (!$full && !$shape->lists('field:' . $nameKey))) {
                continue;
            }
            $readCodes[] = $code;
            $wanted[] = $nameKey;
        }
        $values = $readCodes === [] ? [] : $this->scope(
            fn (): array => $this->fieldValues($eventId, $readCodes, array_values(array_unique($wanted)))
        );

        $names = [];
        foreach ($codes as $code) {
            $nameKey = $nameKeys[$code] ?? null;
            $names[$code] = $nameKey === null ? null : ($values[$code][$nameKey] ?? null);
        }

        return $names;
    }

    /**
     * The view's own condition as the grid shows it above the table, one term per filter term: the column and
     * the values it holds (S37). A parameter term shows the grant's bound value; for full access, which has no
     * bound value, it shows `null` ("the value given at each grant"); for a grant with no bound value it is
     * left out, since the view then holds the whole of it (S36-4).
     *
     * @return list<array{column: string, values: ?list<string>}>
     */
    public function terms(HeldView $view): array
    {
        $shape = $view->shape();
        $terms = [];
        foreach ($shape === null ? [] : $shape->filter as $term) {
            if ($term['in'] !== null) {
                $terms[] = ['column' => $term['column'], 'values' => $term['in']];
            } elseif ($view->bound() !== null) {
                $terms[] = ['column' => $term['column'], 'values' => [$view->bound()]];
            } elseif ($view->grantId === null) {
                $terms[] = ['column' => $term['column'], 'values' => null];
            }
        }

        return $terms;
    }

    private const SQL_COLLATION = 'utf8mb4_unicode_520_ci';

    /**
     * The grid's ad hoc filters and search as `AND …` conditions on the alias `r`, and their arguments in
     * placeholder order. They are appended to the view's own conditions, so they only narrow. Only the view's
     * listed columns can be filtered or searched. Text is matched ignoring case and accents (`utf8mb4_unicode_520_ci`,
     * engineering.md, "Database"); the sorszám is computed in SQL as `RegistrationList` computes it: counted
     * separately within `registered` and within `waitlisted`, in the sorszám order.
     *
     * @return array{0: literal-string, 1: list<string|int>}
     * @throws InvalidInput when a filter names a column the view does not list, or does not fit it
     */
    private function narrowed(HeldView $view, ?Narrowing $narrowing): array
    {
        if ($narrowing === null || $narrowing->isEmpty()) {
            return ['', []];
        }
        $shape = $this->shapeOf($view);
        $listed = array_column($shape->columns, 'column');

        /** @var list<literal-string> $fragments */
        $fragments = [];
        $args = [];
        foreach ($narrowing->filters as $filter) {
            [$fragment, $filterArgs] = $this->filterCondition($filter, $listed, $view->eventId);
            $fragments[] = ' AND ' . $fragment;
            array_push($args, ...$filterArgs);
        }
        foreach ($narrowing->words as $word) {
            $alternatives = [];
            $wordArgs = [];
            foreach ($listed as $column) {
                $condition = $this->wordCondition($word, $column, $view->eventId);
                if ($condition !== null) {
                    $alternatives[] = $condition[0];
                    array_push($wordArgs, ...$condition[1]);
                }
            }
            $fragments[] = $alternatives === [] ? ' AND 0 = 1' : ' AND (' . implode(' OR ', $alternatives) . ')';
            array_push($args, ...$wordArgs);
        }

        return [implode('', $fragments), $args];
    }

    /**
     * One filter as a condition on `r`.
     *
     * @param list<string> $listed
     * @return array{0: literal-string, 1: list<string|int>}
     * @throws InvalidInput
     */
    private function filterCondition(ColumnFilter $filter, array $listed, int $eventId): array
    {
        $column = $filter->column;
        $kind = $filter->kind;
        $fits = match (true) {
            $column === 'code' => $kind === FilterKind::Text,
            $column === 'serial' => $kind === FilterKind::Number,
            $column === 'status' => $kind === FilterKind::Members,
            str_starts_with($column, 'decision:') => $kind === FilterKind::Members || $kind === FilterKind::Text,
            str_starts_with($column, 'field:') => true,
            default => false,
        };
        if (!$fits || !in_array($column, $listed, true)) {
            throw new InvalidInput();
        }
        $values = $this->table(RegistrationTables::FIELD_VALUES);
        $value = $filter->value;
        $text = is_string($value) ? $value : '';

        if ($column === 'serial') {
            // The computed sorszám as a number, with the operators of any number column.
            [$ranked, $rankedArgs] = RegistrationTables::serialNumbers(
                $this->table(RegistrationTables::REGISTRATIONS),
                $eventId
            );
            if (is_array($value)) {
                $compare = 'n.serial_no BETWEEN CAST(%s AS DECIMAL(26,8)) AND CAST(%s AS DECIMAL(26,8))';
                $compareArgs = $value;
            } else {
                $compare = 'n.serial_no' . match ($filter->operator) {
                    'lessThan' => ' < ',
                    'greaterThan' => ' > ',
                    default => ' = ',
                } . 'CAST(%s AS DECIMAL(26,8))';
                $compareArgs = [$text];
            }

            return [
                'r.code IN (SELECT n.code FROM (' . $ranked . ') n WHERE ' . $compare . ')',
                [...$rankedArgs, ...$compareArgs],
            ];
        }
        if ($column === 'code' && is_string($value)) {
            return ['r.code LIKE %s COLLATE ' . self::SQL_COLLATION, [self::likeAnywhere($value)]];
        }
        if ($column === 'status' && is_array($value)) {
            $in = implode(', ', array_fill(0, count($value), '%s'));

            $not = $filter->operator === 'isAny' ? '' : 'NOT ';

            return ['r.status ' . $not . 'IN (' . $in . ')', $value];
        }
        if (str_starts_with($column, 'decision:')) {
            [$state, $kindArgs] = $this->decisionState(substr($column, 9));
            if (is_array($value)) {
                return $this->decisionMembers($filter, $state, $kindArgs, $value);
            }

            return [
                $state . ' LIKE %s COLLATE ' . self::SQL_COLLATION,
                [...$kindArgs, self::likeAnywhere($text)],
            ];
        }

        // A participant field: its stored value, in the one row of the field.
        $key = substr($column, 6);
        $field = 'SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code AND f.field_key = %s AND ';
        $base = [$values, $key];

        switch ($kind) {
            case FilterKind::Members:
                $members = is_array($value) ? array_values(array_diff($value, [ColumnFilter::NO_ANSWER])) : [];
                $none = is_array($value) && in_array(ColumnFilter::NO_ANSWER, $value, true);
                $parts = [];
                $partArgs = [];
                if ($members !== []) {
                    [$match, $matchArgs] = self::memberMatch('f', $members);
                    $parts[] = 'EXISTS (' . $field . $match . ')';
                    array_push($partArgs, ...$base, ...$matchArgs);
                }
                if ($none) {
                    // „Nincs válasz”: no non-empty value in the field's row.
                    $parts[] = 'NOT EXISTS (' . $field . "f.value <> '')";
                    array_push($partArgs, ...$base);
                }
                $any = '(' . implode(' OR ', $parts) . ')';

                return [$filter->operator === 'isAny' ? $any : 'NOT ' . $any, $partArgs];
            case FilterKind::Text:
                return [
                    'EXISTS (' . $field . 'f.value LIKE %s COLLATE ' . self::SQL_COLLATION . ')',
                    [...$base, self::likeAnywhere($text)],
                ];
            case FilterKind::Number:
                $number = "f.value <> '' AND CAST(f.value AS DECIMAL(26,8))";
                if (is_array($value)) {
                    return [
                        'EXISTS (' . $field . $number . ' BETWEEN CAST(%s AS DECIMAL(26,8))'
                        . ' AND CAST(%s AS DECIMAL(26,8)))',
                        [...$base, ...$value],
                    ];
                }
                $comparison = match ($filter->operator) {
                    'lessThan' => ' < ',
                    'greaterThan' => ' > ',
                    default => ' = ',
                };

                return [
                    'EXISTS (' . $field . $number . $comparison . 'CAST(%s AS DECIMAL(26,8)))',
                    [...$base, (string) $value],
                ];
            case FilterKind::Date:
                return [
                    'EXISTS (' . $field . "f.value <> '' AND LEFT(f.value, 10)"
                    . ($filter->operator === 'before' ? ' < ' : ' > ') . '%s)',
                    [...$base, $text],
                ];
            default:
                // Yes/no: a stored 1 is yes and a stored 0 is no; no value is neither, and „Nincs válasz” is that.
                if ($value === ColumnFilter::NO_ANSWER) {
                    $answered = 'EXISTS (' . $field . "f.value <> '')";

                    return [$filter->operator === 'is' ? 'NOT ' . $answered : $answered, $base];
                }
                $stored = $value === true ? '1' : '0';
                $exists = 'EXISTS (' . $field . 'f.value = %s)';

                return [$filter->operator === 'is' ? $exists : 'NOT ' . $exists, [...$base, $stored]];
        }
    }

    /**
     * A decision column's `isAny` / `isNone` over its latest state; `NO_ANSWER` in the list is „no state”.
     *
     * @param literal-string $state the latest state of the kind, as a select expression
     * @param list<string|int> $kindArgs the placeholders' arguments of one use of `$state`
     * @param list<string> $value
     * @return array{0: literal-string, 1: list<string|int>}
     */
    private function decisionMembers(ColumnFilter $filter, string $state, array $kindArgs, array $value): array
    {
        $states = array_values(array_diff($value, [ColumnFilter::NO_ANSWER]));
        $none = in_array(ColumnFilter::NO_ANSWER, $value, true);
        $in = implode(', ', array_fill(0, count($states), '%s'));
        /** @var list<literal-string> $parts */
        $parts = [];
        $args = [];
        if ($filter->operator === 'isAny') {
            if ($states !== []) {
                $parts[] = $state . ' IN (' . $in . ')';
                array_push($args, ...$kindArgs, ...$states);
            }
            if ($none) {
                $parts[] = $state . ' IS NULL';
                array_push($args, ...$kindArgs);
            }

            return ['(' . implode(' OR ', $parts) . ')', $args];
        }
        // None of them: a decision with no record is none of the states, unless „Nincs válasz” is named.
        if ($states !== []) {
            $parts[] = 'NOT (COALESCE(' . $state . ", '') IN (" . $in . '))';
            array_push($args, ...$kindArgs, ...$states);
        }
        if ($none) {
            $parts[] = $state . ' IS NOT NULL';
            array_push($args, ...$kindArgs);
        }

        return ['(' . implode(' AND ', $parts) . ')', $args];
    }

    /**
     * One search word on one listed column, as a condition on `r`; null when the column cannot match it.
     *
     * @return array{0: literal-string, 1: list<string|int>}|null
     */
    private function wordCondition(SearchWord $word, string $column, int $eventId): ?array
    {
        $like = self::likeAnywhere($word->likes[$column] ?? $word->text);

        if ($column === 'code') {
            return ['r.code LIKE %s COLLATE ' . self::SQL_COLLATION, [$like]];
        }
        if ($column === 'serial') {
            [$ranked, $rankedArgs] = RegistrationTables::serialNumbers(
                $this->table(RegistrationTables::REGISTRATIONS),
                $eventId
            );

            return [
                'r.code IN (SELECT n.code FROM (' . $ranked . ') n WHERE CAST(n.serial_no AS CHAR) LIKE %s)',
                [...$rankedArgs, $like],
            ];
        }
        $keys = $word->keys[$column] ?? null;
        if ($column === 'status') {
            return $keys === null || $keys === [] ? null : [
                'r.status IN (' . implode(', ', array_fill(0, count($keys), '%s')) . ')',
                $keys,
            ];
        }
        if (str_starts_with($column, 'decision:')) {
            [$state, $kindArgs] = $this->decisionState(substr($column, 9));
            if ($keys === null) {
                return ['(' . $state . ' LIKE %s COLLATE ' . self::SQL_COLLATION . ')', [...$kindArgs, $like]];
            }

            return $keys === [] ? null : [
                '(' . $state . ' IN (' . implode(', ', array_fill(0, count($keys), '%s')) . '))',
                [...$kindArgs, ...$keys],
            ];
        }
        if (!str_starts_with($column, 'field:')) {
            return null;
        }
        $field = 'EXISTS (SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code'
            . ' AND f.field_key = %s AND ';
        $base = [$this->table(RegistrationTables::FIELD_VALUES), substr($column, 6)];
        if ($keys === null) {
            return [$field . 'f.value LIKE %s COLLATE ' . self::SQL_COLLATION . ')', [...$base, $like]];
        }
        if ($keys === []) {
            return null;
        }
        [$match, $matchArgs] = self::memberMatch('f', $keys);

        return [$field . $match . ')', [...$base, ...$matchArgs]];
    }

    /**
     * A stored choice value matches one of the members: a single choice stores the value, a multiple choice
     * a JSON list of values.
     *
     * @param literal-string $alias the field values' alias
     * @param list<string> $members at least one
     * @return array{0: literal-string, 1: list<string>}
     */
    private static function memberMatch(string $alias, array $members): array
    {
        $in = implode(', ', array_fill(0, count($members), '%s'));
        /** @var literal-string $contains */
        $contains = implode(
            ' OR ',
            array_fill(0, count($members), 'JSON_CONTAINS(' . $alias . '.value, JSON_QUOTE(%s))')
        );

        return [
            '(' . $alias . '.value IN (' . $in . ') OR (LEFT(' . $alias . ".value, 1) = '[' AND JSON_VALID(" . $alias
            . '.value) AND (' . $contains . ')))',
            [...$members, ...$members],
        ];
    }

    /** A LIKE pattern that finds the text anywhere: its own `%`, `_` and `\` are plain characters. */
    private static function likeAnywhere(string $text): string
    {
        return '%' . addcslashes($text, '\\%_') . '%';
    }

    private function shapeOf(HeldView $view): ViewShape
    {
        return $view->shape() ?? $this->masterShape($view->eventId);
    }

    /**
     * The master view of an event: every field key stored in it and every decision kind recorded in it or with a
     * base state.
     */
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
     * The given codes that are registrations of the event and that the view's filter holds now, as a set. One
     * statement on the event's rows; the view's own conditions are reused, so a code outside the filter is left
     * out and never read further.
     *
     * @param list<string> $codes
     * @return array<string, true>
     */
    private function heldCodes(HeldView $view, array $codes): array
    {
        [$conditions, $conditionArgs] = $this->conditions($view->shape(), $view->bound());
        $rows = $this->select(
            'held_code',
            'SELECT r.code FROM %i r WHERE r.event_id = %d AND r.code IN ('
            . implode(', ', array_fill(0, count($codes), '%s')) . ')' . $conditions,
            [$this->table(RegistrationTables::REGISTRATIONS), $view->eventId, ...$codes, ...$conditionArgs]
        );
        $held = [];
        foreach ($rows as $row) {
            $held[(string) $row['code']] = true;
        }

        return $held;
    }

    /**
     * The slot form (its id and the submitted version) of each code's first submission, by code; a code without
     * one is left out. One statement on the submissions' event-first key.
     *
     * @param list<string> $codes
     * @return array<string, array{form_id: int, form_version: int}>
     */
    private function slotForms(int $eventId, array $codes): array
    {
        $rows = $this->select(
            'slot_form',
            'SELECT code, form_id, form_version FROM %i WHERE event_id = %d AND number = 1 AND code IN ('
            . implode(', ', array_fill(0, count($codes), '%s')) . ')',
            [$this->table(RegistrationTables::SUBMISSIONS), $eventId, ...$codes]
        );
        $forms = [];
        foreach ($rows as $row) {
            $forms[(string) $row['code']] = [
                'form_id' => (int) $row['form_id'],
                'form_version' => (int) $row['form_version'],
            ];
        }

        return $forms;
    }

    /**
     * One aggregate as a select expression over the alias `r`, with its arguments in placeholder order.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private function aggregate(AggregateSpec $spec, string $values): array
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
            [$state, $stateArgs] = $this->decisionState(substr($spec->column, 9));

            return [
                'COALESCE(SUM(' . $state . ' IN (' . $in . ')), 0)',
                [...$stateArgs, ...$spec->members],
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
                [$state, $stateArgs] = $this->decisionState(substr($column, 9));
                $fragments[] = ' AND ' . $state . ' IN (' . $in . ')';
                array_push($args, ...$stateArgs, ...$values);
            }
        }

        return [implode('', $fragments), $args];
    }

    /**
     * The current state of a decision kind for the row `r`, as a column of the grid reads it: the one SQL read
     * (`DecisionTables::stateOf()`), with the kind's declared base state.
     *
     * @param literal-string $outer
     * @param literal-string $inner
     * @return array{0: literal-string, 1: list<string>}
     */
    private function decisionState(string $kind, string $outer = 'd', string $inner = 'm'): array
    {
        $default = $this->decisions->kind($kind)?->default;

        return DecisionTables::stateOf(
            'r',
            $outer,
            $inner,
            $this->table(DecisionTables::DECISIONS),
            $kind,
            $default?->state,
            $default === null ? [] : $default->statusValues()
        );
    }
}
