<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

/**
 * A form's field blocks that leave a label empty show the participant field's text instead
 * (events-and-forms.md, "Fields"): a field block's `label` and a choice option's `label` (matched by value).
 * A label the form author wrote is never replaced. The texts come from the host (`FormHost::labelFallbacks()`);
 * this class only reads blocks and fills them, so the engine knows no participant field.
 * [UNAPPROVED N806 of #231]
 */
final class LabelFallback
{
    /**
     * The keys of the field blocks (the names in `$fieldBlocks`) with an empty label, or an empty option label.
     *
     * @param array<array-key, mixed> $blocks
     * @param list<string> $fieldBlocks the block names of the contributed field types
     * @return list<string>
     */
    public static function keys(array $blocks, array $fieldBlocks): array
    {
        $keys = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $key = $attributes['key'] ?? null;
            if (is_string($key) && $key !== '' && in_array($block['blockName'] ?? null, $fieldBlocks, true)) {
                $options = is_array($attributes['options'] ?? null) ? $attributes['options'] : [];
                $emptyOptions = array_filter($options, self::blankOption(...)) !== [];
                if ($emptyOptions || self::blank($attributes['label'] ?? null)) {
                    $keys[] = $key;
                }
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            array_push($keys, ...self::keys($inner, $fieldBlocks));
        }

        return array_values(array_unique($keys));
    }

    /**
     * The blocks with each empty label filled from the fallbacks of its key; a key without one is left as it is.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $fieldBlocks
     * @param array<string, array{title: string, options: array<string, string>}> $fallbacks
     * @return list<array<string, mixed>>
     */
    public static function fill(array $blocks, array $fieldBlocks, array $fallbacks): array
    {
        foreach ($blocks as $index => $block) {
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $key = $attributes['key'] ?? null;
            $fallback = is_string($key) ? ($fallbacks[$key] ?? null) : null;
            if ($fallback !== null && in_array($block['blockName'] ?? null, $fieldBlocks, true)) {
                if (self::blank($attributes['label'] ?? null)) {
                    $attributes['label'] = $fallback['title'];
                }
                if (is_array($attributes['options'] ?? null)) {
                    foreach ($attributes['options'] as $position => $option) {
                        $value = is_array($option) ? ($option['value'] ?? null) : null;
                        if (is_string($value) && self::blankOption($option) && isset($fallback['options'][$value])) {
                            $attributes['options'][$position]['label'] = $fallback['options'][$value];
                        }
                    }
                }
                $blocks[$index]['attrs'] = $attributes;
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var list<array<string, mixed>> $inner */
                $inner = $block['innerBlocks'];
                $blocks[$index]['innerBlocks'] = self::fill($inner, $fieldBlocks, $fallbacks);
            }
        }

        return $blocks;
    }

    private static function blank(mixed $label): bool
    {
        return !is_string($label) || trim($label) === '';
    }

    private static function blankOption(mixed $option): bool
    {
        return is_array($option) && self::blank($option['label'] ?? null);
    }
}
