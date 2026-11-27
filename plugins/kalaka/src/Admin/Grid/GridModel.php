<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\AggregateKind;
use Kalaka\Admin\Api\AggregateSpec;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The grid's columns and its sum row for one held view (S37). The columns come from the view query, in the
 * order Sorszám, Kód, Név, Állapot, then the rest (the other columns keep their listed slots, and the
 * field columns fill the field slots in the fields' declared order); each carries what the app needs to
 * show it. The sum row's rule is fixed for Kaláka's own columns and the
 * confirmation, and follows the field's type for a participant field (owner ruling 2026-11-17):
 *
 * - a field with options: the members counted for the view (`counts`), none until some are chosen;
 * - a consent: how many said yes;
 * - a scale (`FieldDefinitions::SCALE_TYPE`): the average; any other number-valued field: the total;
 * - an optional text-valued field: how many filled it in; a required one, a date or anything else: nothing.
 */
final class GridModel
{
    /** The status members counted when a view stores none (owner, round 4). */
    public const DEFAULT_STATUS_COUNTS = ['registered'];

    /** The display name field's type: its column comes third. */
    private const NAME_TYPE = 'kalaka/name-field';

    /** @var array<string, array{label: string, states: array<string, string>}> by event and kind */
    private array $kinds = [];

    public function __construct(
        private readonly ViewQuery $query = new ViewQuery(),
        private readonly Decisions $decisions = new Decisions(),
    ) {
    }

    /**
     * The view's columns as the app shows them.
     *
     * @param array<string, FieldDefinition> $fields
     * @return list<array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>, empty?: true
     * }>
     */
    public function columns(HeldView $view, array $fields): array
    {
        $listed = array_column($this->query->columns($view), 'column');

        $columns = [];
        foreach ($this->ordered($listed, $fields) as $column) {
            $columns[] = $this->meta($column, $fields, $view->eventId);
        }

        return $columns;
    }

    /**
     * The columns to order by number, as the column metadata marks them `numeric`: the number-valued
     * fields, besides the sorszám, which the grid does not sort. For the master view it names every
     * numeric field of the event; one with no stored value is not listed, so it is never sorted.
     *
     * @param array<string, FieldDefinition> $fields
     * @return list<string>
     */
    public function numericColumns(HeldView $view, array $fields): array
    {
        $shape = $view->shape();
        $listed = $shape === null
            ? array_map(static fn (string $key): string => 'field:' . $key, array_keys($fields))
            : array_column($shape->columns, 'column');
        $numeric = [];
        foreach ($listed as $column) {
            // `meta()` marks no other column numeric; skipping the rest avoids reading the decision kinds.
            if (
                ($column === 'serial' || str_starts_with($column, 'field:'))
                && $this->meta($column, $fields, $view->eventId)['numeric']
            ) {
                $numeric[] = $column;
            }
        }

        return $numeric;
    }

    /**
     * A decision kind as the grid shows it: the label and the states' labels its module declares; for a
     * kind nothing declares, the kind's key and the states recorded in the event, each by its key.
     *
     * @return array{label: string, states: array<string, string>}
     */
    private function decision(int $eventId, string $kind): array
    {
        return $this->kinds[$eventId . ':' . $kind] ??= $this->describeKind($eventId, $kind);
    }

    /** @return array{label: string, states: array<string, string>} */
    private function describeKind(int $eventId, string $kind): array
    {
        $declared = $this->decisions->kind($kind);
        if ($declared !== null) {
            return ['label' => $declared->label, 'states' => $declared->states];
        }
        $states = [];
        foreach ($this->decisions->statesIn($eventId, $kind) as $state) {
            $states[$state] = $state;
        }

        return ['label' => $kind, 'states' => $states];
    }

    /**
     * The tab of a held view is keyed per grant: `g{grant id}` for a view held through a grant, otherwise
     * (full access) `v{view id}`, `v0` for the master view.
     */
    public static function tabKey(HeldView $view): string
    {
        return $view->grantId !== null ? 'g' . $view->grantId : 'v' . ($view->viewId ?? 0);
    }

    /**
     * The tab's name: the view's name, and for a grant with a bound value „{nézet} – {érték}” (the value as
     * the dialog shows it). A grant without a bound value, or with a blank one, is named by the view alone;
     * the one without a bound value sees the whole view. The master view is „Jelentkezők”.
     *
     * @param array<string, FieldDefinition> $fields
     */
    public function tabName(HeldView $view, array $fields): string
    {
        $name = $view->name ?? Texts::REGISTRANTS;
        $column = $view->shape()?->parameterColumn();
        $bound = $view->bound();
        if ($column === null || $bound === null || trim($bound) === '') {
            return $name;
        }

        return sprintf(Texts::TAB_BOUND, $name, $this->valueLabel($column, $bound, $fields, $view->eventId));
    }

    /**
     * A column's member as the grid shows it: the option's label or the decision state's label, else the value.
     *
     * @param array<string, FieldDefinition> $fields
     */
    public function valueLabel(string $column, string $value, array $fields, int $eventId): string
    {
        foreach ($this->describe([$column], $fields, $eventId) as $meta) {
            return $meta['options'][$value] ?? $meta['states'][$value] ?? $value;
        }

        return $value;
    }

    /**
     * The given columns as the app shows them, in the given order.
     *
     * @param list<string> $columns
     * @param array<string, FieldDefinition> $fields
     * @return list<array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>, empty?: true
     * }>
     */
    public function describe(array $columns, array $fields, int $eventId): array
    {
        return array_map(fn (string $column): array => $this->meta($column, $fields, $eventId), $columns);
    }

    /**
     * The counted members in force for the view, by column: what is stored (with the view, or in the event's
     * master row for the master view), members that no longer exist left out, and Állapot's default where
     * nothing is stored.
     *
     * @param array<string, FieldDefinition> $fields
     * @return array<string, list<string>>
     */
    public function counts(HeldView $view, array $fields): array
    {
        $stored = $view->counts();
        $counts = [];
        foreach ($stored as $column => $members) {
            $valid = array_column($this->members($column, $fields, $view->eventId) ?? [], 'value');
            $members = array_values(array_intersect($members, $valid));
            if ($members !== []) {
                $counts[$column] = $members;
            }
        }
        $counts['status'] ??= self::DEFAULT_STATUS_COUNTS;

        return $counts;
    }

    /**
     * The stored counted members with an ad hoc choice on top (the grant holder's own counting, G1): a
     * column must be listed and carry members, and each member must belong to it. An empty list drops the
     * column's entry, so it counts its default again.
     *
     * @param array<string, list<string>> $override
     * @param array<string, list<string>> $counts the stored choice, with the default
     * @param list<string> $listed
     * @param array<string, FieldDefinition> $fields
     * @return array<string, list<string>>
     * @throws \Kalaka\Foundation\Api\Error\InvalidInput
     */
    private function withOverride(array $override, array $counts, array $listed, array $fields, int $eventId): array
    {
        foreach ($override as $column => $members) {
            if (!in_array($column, $listed, true)) {
                throw new \Kalaka\Foundation\Api\Error\InvalidInput();
            }
            $known = array_column($this->members($column, $fields, $eventId) ?? [], 'value');
            if ($known === []) {
                throw new \Kalaka\Foundation\Api\Error\InvalidInput();
            }
            $seen = [];
            foreach ($members as $member) {
                if (!in_array($member, $known, true) || isset($seen[$member])) {
                    throw new \Kalaka\Foundation\Api\Error\InvalidInput();
                }
                $seen[$member] = true;
            }
            if ($members === []) {
                unset($counts[$column]);
            } else {
                $counts[$column] = $members;
            }
        }

        return $counts;
    }

    /**
     * The sum row over the whole view, by column; a column without a sum is left out. One aggregate statement.
     * With a narrowing (the grid's filters and search) it covers the narrowed set; the counted members are the
     * view's own, or a grant holder's ad hoc override.
     *
     * `$override` is an ad hoc choice of counted members on top of the stored ones (the grant holder's own
     * counting, which is never saved to the shared view; topic 6a G1). A column missing from it keeps the
     * stored choice; an empty list drops the column's entry. Only a listed column with members can be
     * overridden.
     *
     * @param array<string, FieldDefinition> $fields
     * @param array<string, list<string>>|null $override
     * @return array<string, array{
     *     kind: string, n: int|string|null, members?: list<string>, labels?: list<string>, member?: string
     * }>
     */
    public function sums(HeldView $view, array $fields, ?Narrowing $narrowing = null, ?array $override = null): array
    {
        $listed = array_column($this->query->columns($view), 'column');
        $counts = $this->counts($view, $fields);
        if ($override !== null) {
            $counts = $this->withOverride($override, $counts, $listed, $fields, $view->eventId);
            // A cleared status choice counts its default again, as a cleared stored one does.
            $counts['status'] ??= self::DEFAULT_STATUS_COUNTS;
        }

        $specs = [];
        $extra = [];
        foreach ($listed as $column) {
            $rule = $this->rule($column, $fields, $counts, $view->eventId);
            if ($rule === null) {
                continue;
            }
            [$kind, $members, $shown] = $rule;
            $specs[$column] = new AggregateSpec($column, $kind, $members);
            $extra[$column] = $shown;
        }
        if ($specs === []) {
            return [];
        }

        $values = $this->query->aggregates($view, array_values($specs), $narrowing);
        $sums = [];
        foreach (array_keys($specs) as $index => $column) {
            $sums[$column] = ['n' => $values[$index]] + $extra[$column];
        }

        return $sums;
    }

    /**
     * The view's own condition in words, as the dialog words it (S37): each term as „{oszlop}: {értékek}”,
     * joined with „; ”; a term whose value is given at each grant names that. Null for a view without one.
     *
     * @param list<array{column: string, values: ?list<string>}> $terms
     * @param array<string, FieldDefinition> $fields
     */
    public function conditionText(array $terms, array $fields, int $eventId): ?string
    {
        $parts = [];
        foreach ($terms as $term) {
            $meta = $this->describe([$term['column']], $fields, $eventId)[0];
            $parts[] = $meta['label'] . ': ' . ($term['values'] === null ? Texts::PER_GRANT : implode(', ', array_map(
                static fn (string $value): string => $meta['options'][$value] ?? $meta['states'][$value] ?? $value,
                $term['values']
            )));
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * What the grid shows above the table (hole 4): the view's condition for its holder, a grant holder with
     * their bound value.
     *
     * @param array<string, FieldDefinition> $fields
     * @return array{text: string, terms: int}|null
     */
    public function condition(HeldView $view, array $fields): ?array
    {
        $terms = $this->query->terms($view);
        $text = $this->conditionText($terms, $fields, $view->eventId);

        return $text === null ? null : ['text' => $text, 'terms' => count($terms)];
    }

    /**
     * The aggregate of one column: its kind, its members, and what the app is told besides the number.
     *
     * @param array<string, FieldDefinition> $fields
     * @param array<string, list<string>> $counts
     * @return array{
     *     0: AggregateKind,
     *     1: list<string>,
     *     2: array{kind: string, members?: list<string>, labels?: list<string>, member?: string}
     * }|null
     */
    private function rule(string $column, array $fields, array $counts, int $eventId): ?array
    {
        if ($column === 'code') {
            return [AggregateKind::Rows, [], ['kind' => 'rows']];
        }
        if ($column === 'status' || isset($counts[$column])) {
            $chosen = $counts[$column] ?? [];
            if ($chosen === []) {
                return null;
            }
            $labels = array_column($this->members($column, $fields, $eventId) ?? [], 'label', 'value');

            return [AggregateKind::Members, $chosen, [
                'kind' => 'members',
                'members' => $chosen,
                'labels' => array_map(static fn (string $m): string => $labels[$m] ?? $m, $chosen),
            ]];
        }
        if ($column === 'awaits_answer') {
            return [AggregateKind::Yes, [], ['kind' => 'count', 'member' => Texts::YES]];
        }
        if (!str_starts_with($column, 'field:')) {
            return null;
        }
        $field = $fields[substr($column, 6)] ?? null;
        if ($field === null || $field->options !== []) {
            return null;
        }

        return match (true) {
            $field->type === FieldDefinitions::CONSENT_TYPE
                => [AggregateKind::Yes, [], ['kind' => 'count', 'member' => Texts::YES]],
            $field->type === FieldDefinitions::SCALE_TYPE => [AggregateKind::Average, [], ['kind' => 'average']],
            $field->valueType === 'number' => [AggregateKind::Sum, [], ['kind' => 'sum']],
            $field->valueType === 'text' && !$field->required => [AggregateKind::Filled, [], ['kind' => 'filled']],
            default => null,
        };
    }

    /**
     * The members a column's sum can count, with their labels: the statuses, or a field's options; null for
     * any other column.
     *
     * @param array<string, FieldDefinition> $fields
     * @return list<array{value: string, label: string}>|null
     */
    private function members(string $column, array $fields, int $eventId): ?array
    {
        if (str_starts_with($column, 'decision:')) {
            $states = $this->decision($eventId, substr($column, 9))['states'];
            $members = [];
            foreach ($states as $value => $label) {
                $members[] = ['value' => (string) $value, 'label' => $label];
            }

            return $members;
        }
        if ($column === 'status') {
            return array_map(
                static fn (RegistrationStatus $s): array => ['value' => $s->value, 'label' => Texts::status($s)],
                RegistrationStatus::cases()
            );
        }
        $field = str_starts_with($column, 'field:') ? ($fields[substr($column, 6)] ?? null) : null;
        if ($field === null || $field->options === []) {
            return null;
        }
        $members = [];
        foreach ($field->options as $value => $label) {
            $members[] = ['value' => (string) $value, 'label' => $label];
        }

        return $members;
    }

    /**
     * @param list<string> $listed
     * @param array<string, FieldDefinition> $fields
     * @return list<string>
     */
    private function ordered(array $listed, array $fields): array
    {
        $name = null;
        foreach ($fields as $field) {
            if ($field->type === self::NAME_TYPE && in_array('field:' . $field->key, $listed, true)) {
                $name = 'field:' . $field->key;
                break;
            }
        }
        $first = array_values(array_filter(
            ['code', $name, 'status', 'serial'],
            static fn (?string $c): bool => $c !== null && in_array($c, $listed, true)
        ));

        // The other columns keep their listed slots; the field columns fill the slots fields hold, in the
        // fields' declared order (a field no longer declared last, then by listed order).
        $positions = array_flip(array_map(static fn (string $key): string => 'field:' . $key, array_keys($fields)));
        $rest = array_values(array_diff($listed, $first));
        $order = array_flip($rest);
        $fieldColumns = array_values(array_filter($rest, static fn (string $c): bool => str_starts_with($c, 'field:')));
        usort(
            $fieldColumns,
            static fn (string $a, string $b): int => [$positions[$a] ?? PHP_INT_MAX, $order[$a]]
                <=> [$positions[$b] ?? PHP_INT_MAX, $order[$b]]
        );
        $next = 0;
        foreach ($rest as $slot => $column) {
            if (str_starts_with($column, 'field:')) {
                $rest[$slot] = $fieldColumns[$next++];
            }
        }

        return [...$first, ...$rest];
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>, empty?: true
     * }
     */
    private function meta(string $column, array $fields, int $eventId): array
    {
        // Kód carries the default order and sorts both ways; Szám is a plain number sort. [UNAPPROVED N60]
        // The two times are display-only.
        $displayOnly = in_array($column, ['registered_at', 'submitted_at'], true);
        $meta = ['column' => $column, 'sortable' => !$displayOnly, 'numeric' => false];
        if ($column === 'code') {
            return $meta + ['label' => Texts::CODE, 'type' => 'code'];
        }
        if ($column === 'serial') {
            return ['numeric' => true] + $meta + ['label' => Texts::SERIAL, 'type' => 'serial'];
        }
        if ($column === 'registered_at' || $column === 'submitted_at') {
            return $meta + [
                'label' => $column === 'registered_at' ? Texts::REGISTERED_AT : Texts::SUBMITTED_AT,
                'type' => 'time',
            ];
        }
        if ($column === 'awaits_answer') {
            // A core-derived yes/no column [UNAPPROVED N669]: shown, filtered and counted as a consent field is
            // (stored '1' / '0'), but with three values: no value is N/A (the registration is not `registered`),
            // shown as `na` („–”), different from „Nem”; there is no „Nincs válasz” and no N/A filter option.
            // The title is [UNAPPROVED T852]. [UNAPPROVED N690, T1120]
            return $meta + [
                'label' => Texts::AWAITS_ANSWER,
                'type' => 'consent',
                'options' => ['1' => Texts::YES, '0' => Texts::NO],
                'na' => Texts::NOT_APPLICABLE,
            ];
        }
        if ($column === 'status') {
            $members = $this->members($column, $fields, $eventId) ?? [];

            return $meta + [
                'label' => Texts::STATUS,
                'type' => 'status',
                'options' => array_column($members, 'label', 'value'),
                'members' => $members,
            ];
        }
        if (str_starts_with($column, 'decision:')) {
            $kind = $this->decision($eventId, substr($column, 9));

            // A decision can always have no record, so a list of states offers „Nincs válasz”.
            return $meta + [
                'label' => $kind['label'],
                'type' => 'decision',
                'states' => $kind['states'],
                'members' => $this->members($column, $fields, $eventId) ?? [],
            ] + ($kind['states'] === [] ? [] : ['empty' => true]);
        }

        $field = $fields[substr($column, 6)] ?? null;
        if ($field === null) {
            return $meta + ['label' => substr($column, 6), 'type' => 'text'];
        }
        $type = match (true) {
            $field->options !== [] => 'choice',
            $field->type === FieldDefinitions::CONSENT_TYPE => 'consent',
            $field->valueType === 'date' => 'date',
            $field->valueType === 'number' => 'number',
            default => 'text',
        };
        $meta = ['numeric' => $type === 'number'] + $meta + ['label' => $field->title, 'type' => $type];
        if ($type === 'choice') {
            $meta['options'] = $field->options;
            $meta['members'] = $this->members($column, $fields, $eventId) ?? [];
        }
        if ($type === 'consent') {
            $meta['options'] = ['1' => Texts::YES, '0' => Texts::NO];
        }
        // „Nincs válasz” in the filter of every list-type field column, required or not: a required field can still
        // be empty (behind a condition, in an additional form not yet sent, on a registration made before the field
        // existed). [UNAPPROVED N66]
        if ($type === 'choice' || $type === 'consent') {
            $meta['empty'] = true;
        }

        return $meta;
    }
}
