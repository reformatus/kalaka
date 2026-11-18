<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\AggregateKind;
use Kalaka\Admin\Api\AggregateSpec;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The grid's columns and its sum row for one held view (S37). The columns come from the view query, in the
 * order Sorszám, Kód, Név, Állapot, then the rest (field columns in the fields' declared order); each
 * carries what the app needs to show it. The sum row's rule is fixed for Kaláka's own columns and the
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

    /** The core decision with a fixed count, and the state it counts. */
    private const CONFIRMATION = 'decision:confirmation';
    private const CONFIRMED = 'confirmed';

    public function __construct(
        private readonly ViewQuery $query = new ViewQuery(),
    ) {
    }

    /**
     * The view's columns as the app shows them.
     *
     * @param array<string, FieldDefinition> $fields
     * @return list<array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>
     * }>
     */
    public function columns(HeldView $view, array $fields): array
    {
        $listed = array_column($this->query->columns($view), 'column');

        $columns = [];
        foreach ($this->ordered($listed, $fields) as $column) {
            $columns[] = self::meta($column, $fields);
        }

        return $columns;
    }

    /**
     * The given columns as the app shows them, in the given order.
     *
     * @param list<string> $columns
     * @param array<string, FieldDefinition> $fields
     * @return list<array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>
     * }>
     */
    public function describe(array $columns, array $fields): array
    {
        return array_map(static fn (string $column): array => self::meta($column, $fields), $columns);
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
            $valid = array_column(self::members($column, $fields) ?? [], 'value');
            $members = array_values(array_intersect($members, $valid));
            if ($members !== []) {
                $counts[$column] = $members;
            }
        }
        $counts['status'] ??= self::DEFAULT_STATUS_COUNTS;

        return $counts;
    }

    /**
     * The sum row over the whole view, by column; a column without a sum is left out. One aggregate statement.
     *
     * @param array<string, FieldDefinition> $fields
     * @return array<string, array{
     *     kind: string, n: int|string|null, members?: list<string>, labels?: list<string>, member?: string
     * }>
     */
    public function sums(HeldView $view, array $fields): array
    {
        $listed = array_column($this->query->columns($view), 'column');
        $counts = $this->counts($view, $fields);

        $specs = [];
        $extra = [];
        foreach ($listed as $column) {
            $rule = self::rule($column, $fields, $counts);
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

        $values = $this->query->aggregates($view, array_values($specs));
        $sums = [];
        foreach (array_keys($specs) as $index => $column) {
            $sums[$column] = ['n' => $values[$index]] + $extra[$column];
        }

        return $sums;
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
    private static function rule(string $column, array $fields, array $counts): ?array
    {
        if ($column === 'code') {
            return [AggregateKind::Rows, [], ['kind' => 'rows']];
        }
        if ($column === self::CONFIRMATION) {
            return [AggregateKind::Members, [self::CONFIRMED], ['kind' => 'count', 'member' => Texts::CONFIRMED]];
        }
        if ($column === 'status' || isset($counts[$column])) {
            $chosen = $counts[$column] ?? [];
            if ($chosen === []) {
                return null;
            }
            $labels = array_column(self::members($column, $fields) ?? [], 'label', 'value');

            return [AggregateKind::Members, $chosen, [
                'kind' => 'members',
                'members' => $chosen,
                'labels' => array_map(static fn (string $m): string => $labels[$m] ?? $m, $chosen),
            ]];
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
    private static function members(string $column, array $fields): ?array
    {
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
            ['serial', 'code', $name, 'status'],
            static fn (?string $c): bool => $c !== null && in_array($c, $listed, true)
        ));

        $positions = array_flip(array_map(static fn (string $key): string => 'field:' . $key, array_keys($fields)));
        $rest = array_values(array_diff($listed, $first));
        $order = array_flip($rest);
        usort($rest, static function (string $a, string $b) use ($positions, $order): int {
            $fieldA = str_starts_with($a, 'field:');
            $fieldB = str_starts_with($b, 'field:');
            if ($fieldA && $fieldB) {
                return [$positions[$a] ?? PHP_INT_MAX, $order[$a]] <=> [$positions[$b] ?? PHP_INT_MAX, $order[$b]];
            }

            return $order[$a] <=> $order[$b];
        });

        return [...$first, ...$rest];
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return array{
     *     column: string, label: string, type: string, sortable: bool, numeric: bool,
     *     options?: array<array-key, string>, members?: list<array{value: string, label: string}>,
     *     states?: array<string, string>
     * }
     */
    private static function meta(string $column, array $fields): array
    {
        $meta = ['column' => $column, 'sortable' => $column !== 'code' && $column !== 'serial', 'numeric' => false];
        if ($column === 'code') {
            return $meta + ['label' => Texts::CODE, 'type' => 'code'];
        }
        if ($column === 'serial') {
            return ['numeric' => true] + $meta + ['label' => Texts::SERIAL, 'type' => 'serial'];
        }
        if ($column === 'status') {
            $members = self::members($column, $fields) ?? [];

            return $meta + [
                'label' => Texts::STATUS,
                'type' => 'status',
                'options' => array_column($members, 'label', 'value'),
                'members' => $members,
            ];
        }
        if (str_starts_with($column, 'decision:')) {
            $confirmation = $column === self::CONFIRMATION;

            return $meta + [
                'label' => $confirmation ? Texts::CONFIRMATION : substr($column, 9),
                'type' => 'decision',
                'states' => $confirmation ? [self::CONFIRMED => Texts::CONFIRMED] : [],
            ];
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
            $meta['members'] = self::members($column, $fields) ?? [];
        }
        if ($type === 'consent') {
            $meta['options'] = ['1' => Texts::YES];
        }

        return $meta;
    }
}
