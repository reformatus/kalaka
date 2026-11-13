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

    /**
     * Every key the condition reads, date references included, each once, in order of appearance.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->items as $item) {
            $read = $item instanceof self
                ? $item->keys()
                : [$item->key, ...($item->reference !== null ? [$item->reference->key] : [])];
            foreach ($read as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }
}
