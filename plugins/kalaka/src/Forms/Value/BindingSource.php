<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

/** The Block Bindings source `kalaka/value`, args `{"key": "<value key>"}`. */
final class BindingSource
{
    public const NAME = 'kalaka/value';

    /**
     * The text of the key's value in the context of the `Values::render()` call in progress; null
     * outside one, so the authored content stays. Escaped as text for a rich-text attribute (WordPress
     * only sanitizes it there), plain for an HTML attribute (WordPress escapes it there).
     *
     * @internal the source's `get_value_callback`
     * @param array<string, mixed> $args
     */
    public static function value(array $args, \WP_Block $block, string $attributeName): ?string
    {
        $context = RenderStack::current();
        if ($context === null) {
            return null;
        }

        $key = $args['key'] ?? null;
        $text = $context->get(is_string($key) ? $key : '')->text;
        $source = $block->block_type->attributes[$attributeName]['source'] ?? null;

        // A value is plain text, so every `&` is escaped, even one that looks like an entity (`esc_html()` would not).
        return $source === 'html' || $source === 'rich-text'
            ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8', true)
            : $text;
    }
}
