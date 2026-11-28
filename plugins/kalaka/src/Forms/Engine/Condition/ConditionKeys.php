<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

/**
 * The keys the conditions of a form's blocks refer to (a condition's `key`, and the `key` of a date reference
 * it compares with): a branch's or page's `condition`, a block's `optionConditions` and an option's own
 * `condition`. Only conditions count; text that merely mentions a key declares nothing.
 */
final class ConditionKeys
{
    /**
     * @param array<array<string, mixed>> $blocks parsed blocks (`parse_blocks()`)
     * @return list<string> each key once, in the order met
     */
    public static function in(array $blocks): array
    {
        $keys = [];
        self::collect($blocks, $keys);

        return array_keys($keys);
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param array<string, true> $keys
     */
    private static function collect(array $blocks, array &$keys): void
    {
        foreach ($blocks as $block) {
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            self::condition($attributes['condition'] ?? null, $keys);
            $perOption = $attributes['optionConditions'] ?? null;
            foreach (is_array($perOption) ? $perOption : [] as $condition) {
                self::condition($condition, $keys);
            }
            foreach (is_array($attributes['options'] ?? null) ? $attributes['options'] : [] as $option) {
                self::condition(is_array($option) ? ($option['condition'] ?? null) : null, $keys);
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            self::collect($inner, $keys);
        }
    }

    /** @param array<string, true> $keys */
    private static function condition(mixed $condition, array &$keys): void
    {
        if (!is_array($condition)) {
            return;
        }
        if (is_array($condition['items'] ?? null)) {
            foreach ($condition['items'] as $item) {
                self::condition($item, $keys);
            }

            return;
        }
        if (is_string($condition['key'] ?? null)) {
            $keys[$condition['key']] = true;
        }
        $value = $condition['value'] ?? null;
        if (is_array($value) && is_string($value['key'] ?? null)) {
            $keys[$value['key']] = true;
        }
    }
}
