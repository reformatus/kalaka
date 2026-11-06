<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

/**
 * A parsed condition: a group of comparisons combined by `all` or `any`. The top level of a
 * condition may also hold groups (one level), which are the same class with their own `not`;
 * the top level itself is never negated.
 */
final readonly class Condition
{
    /** @param non-empty-list<Comparison|Condition> $items */
    public function __construct(
        public MatchMode $match,
        public array $items,
        public bool $not = false,
    ) {
    }
}
