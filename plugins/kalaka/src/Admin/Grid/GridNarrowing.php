<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\ColumnFilter;
use Kalaka\Admin\Api\FilterKind;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\SearchWord;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The grid's request for filters and a search as a `Narrowing` for the view query (S37). The client sends
 * `{field, operator, value}` per filter and the search text; the kind of each filter comes from the column's
 * own type here, never from the client. A filter on a column the view does not list, an operator that is not
 * the column's, or a value out of shape is `InvalidInput` (400). The search words are matched against what a
 * cell shows: for the status, a choice, a yes/no and a decision's state, this finds the stored values whose
 * shown word contains the word (ignoring case and accents), and hands those to the view query.
 */
final class GridNarrowing
{
    public function __construct(private readonly GridModel $model = new GridModel())
    {
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param mixed $filters a JSON list of `{field, operator, value}`, or the decoded list; null or '' for none
     * @param mixed $search the search text; null or '' for none
     * @throws InvalidInput
     */
    public function of(HeldView $view, array $fields, mixed $filters, mixed $search): Narrowing
    {
        $columns = [];
        foreach ($this->model->columns($view, $fields) as $meta) {
            $columns[$meta['column']] = $meta;
        }

        return new Narrowing(
            $this->filters($columns, self::decoded($filters)),
            $this->words($columns, $search)
        );
    }

    /** @return list<mixed> */
    private static function decoded(mixed $filters): array
    {
        if ($filters === null || $filters === '') {
            return [];
        }
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }
        if (!is_array($filters) || !array_is_list($filters) || count($filters) > Narrowing::MAX_FILTERS) {
            throw new InvalidInput();
        }

        return $filters;
    }

    /**
     * @param array<string, array<string, mixed>> $columns the view's columns by name
     * @param list<mixed> $raw
     * @return list<ColumnFilter>
     */
    private function filters(array $columns, array $raw): array
    {
        $filters = [];
        foreach ($raw as $one) {
            if (!is_array($one) || !is_string($one['field'] ?? null) || !is_string($one['operator'] ?? null)) {
                throw new InvalidInput();
            }
            $field = $one['field'];
            $filters[] = ColumnFilter::of(
                $field,
                self::kind($columns[$field] ?? null),
                $one['operator'],
                $one['value'] ?? null
            );
        }

        return $filters;
    }

    /**
     * @param array<string, mixed>|null $meta a column's meta (`GridModel::columns()`), null when not listed
     * @throws InvalidInput for a column the view does not list
     */
    private static function kind(?array $meta): FilterKind
    {
        if ($meta === null) {
            throw new InvalidInput();
        }

        return match ($meta['type']) {
            'status', 'choice' => FilterKind::Members,
            'decision' => ($meta['states'] ?? []) === [] ? FilterKind::Text : FilterKind::Members,
            'consent' => FilterKind::YesNo,
            'number', 'serial' => FilterKind::Number,
            'date' => FilterKind::Date,
            default => FilterKind::Text,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     * @return list<SearchWord>
     */
    private function words(array $columns, mixed $search): array
    {
        if ($search === null || $search === '') {
            return [];
        }
        if (!is_string($search) || mb_strlen($search, 'UTF-8') > Narrowing::MAX_SEARCH) {
            throw new InvalidInput();
        }
        $texts = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        if ($texts === false || count($texts) > Narrowing::MAX_WORDS) {
            throw new InvalidInput();
        }

        $words = [];
        foreach ($texts as $text) {
            if (trim($text, '.') === '') {
                continue;
            }
            $folded = self::fold($text);
            $keys = [];
            $likes = [];
            foreach ($columns as $column => $meta) {
                $shown = self::shown($meta);
                if ($shown !== null) {
                    $keys[$column] = array_keys(array_filter(
                        $shown,
                        static fn (string $label): bool => str_contains(self::fold($label), $folded)
                    ));
                    $keys[$column] = array_map('strval', $keys[$column]);
                } elseif ($meta['type'] === 'date') {
                    // Shown as „2026. 07. 12.”, stored as 2026-07-12: a part with its dots is a part of the stored day.
                    $likes[$column] = trim($text, '.');
                } elseif ($meta['type'] === 'number') {
                    $likes[$column] = str_replace(',', '.', $text);
                }
            }
            $words[] = new SearchWord($text, $keys, $likes);
        }

        return $words;
    }

    /**
     * The words a column shows for its stored values, when the shown word is not the stored value; null for a
     * column whose cell shows the stored text.
     *
     * @param array<string, mixed> $meta
     * @return array<array-key, string>|null
     */
    private static function shown(array $meta): ?array
    {
        $words = match ($meta['type']) {
            'status', 'choice', 'consent' => $meta['options'] ?? [],
            'decision' => ($meta['states'] ?? []) === [] ? null : $meta['states'],
            default => null,
        };

        return is_array($words) ? $words : null;
    }

    /** Ignoring case and accents: „Árpád” and „ARPAD” both give „arpad”. */
    private static function fold(string $text): string
    {
        return remove_accents(mb_strtolower($text, 'UTF-8'));
    }
}
