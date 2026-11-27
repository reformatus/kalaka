<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The grid's ad hoc filters and search on top of a held view (S37). They only ever narrow: the view's own
 * filter and bound value stay in force, and the view query appends these to them. Every filter and every
 * search word is ANDed. The caps keep one request's SQL small.
 */
final readonly class Narrowing
{
    /** The most filters in one request: one per column, and the pseudo column. */
    public const MAX_FILTERS = 40;

    /** The most values in one list (a choice's options, the columns of „Nincs válasz”). */
    public const MAX_VALUES = 100;

    /** The longest stored value or column name in a list, in characters. */
    public const MAX_VALUE_LENGTH = 200;

    /** The longest search or text filter, in characters. */
    public const MAX_SEARCH = 100;

    /** The most words a search is split into. */
    public const MAX_WORDS = 10;

    /**
     * @param list<ColumnFilter> $filters
     * @param list<SearchWord> $words
     * @throws InvalidInput past a cap, or twice the same column
     */
    public function __construct(public array $filters = [], public array $words = [])
    {
        if (count($filters) > self::MAX_FILTERS || count($words) > self::MAX_WORDS) {
            throw new InvalidInput();
        }
        $seen = [];
        foreach ($filters as $filter) {
            if (isset($seen[$filter->column])) {
                throw new InvalidInput();
            }
            $seen[$filter->column] = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->filters === [] && $this->words === [];
    }
}
