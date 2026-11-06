<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

/** One comparison of a value with a given one; `value` is null exactly for `empty`. */
final readonly class Comparison
{
    public function __construct(
        public string $key,
        public Operator $operator,
        public ?string $value,
        public bool $not,
    ) {
    }
}
