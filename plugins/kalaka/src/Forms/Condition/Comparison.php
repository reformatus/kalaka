<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

/**
 * One comparison of a value with a given one: a fixed `value`, or for a date a `reference` to
 * another date key, moved. Both are null exactly for `empty`; never both set.
 */
final readonly class Comparison
{
    public function __construct(
        public string $key,
        public Operator $operator,
        public ?string $value,
        public bool $not,
        public ?DateReference $reference = null,
    ) {
    }
}
