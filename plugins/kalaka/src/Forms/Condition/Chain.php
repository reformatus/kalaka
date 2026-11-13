<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

/** A chain of branch blocks: a `kalaka/if`, any number of `kalaka/else-if` and at most one `kalaka/else`. */
final readonly class Chain
{
    /** @param non-empty-list<array<string, mixed>> $branches the parsed branch blocks, in order */
    public function __construct(public array $branches)
    {
    }
}
