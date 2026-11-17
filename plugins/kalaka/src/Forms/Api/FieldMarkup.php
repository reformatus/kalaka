<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Engine\FieldMarkup as EngineFieldMarkup;

/**
 * The shared pieces of a field block's markup, for an extension's field type (its block's `render.php`):
 * the wrapper, the classes of each element role (core's own classes, then the theme adapter's), the
 * required marker and the error element, the same as core's own field blocks render them, and the answer
 * the field opens with. Every string it returns is already escaped. An extension's field type uses these,
 * so the theme adapter reaches its markup too and core never changes markup an extension produced
 * (modules-and-extensions.md, "Contracts").
 */
final class FieldMarkup
{
    /** The id of a field's control, which its `label` names. */
    public static function controlId(string $key): string
    {
        return EngineFieldMarkup::controlId($key);
    }

    /** The wrapper element: `p` when the adapter chooses it, but only for a single text control. */
    public static function wrapperTag(bool $singleControl): string
    {
        return EngineFieldMarkup::wrapperTag($singleControl);
    }

    /**
     * The wrapper's attributes: the block's own (`wp-block-kalaka-…`, and what the block supports add),
     * `kalaka-field`, the adapter's field classes and `data-kalaka-field`.
     */
    public static function wrapperAttributes(string $key): string
    {
        return EngineFieldMarkup::wrapperAttributes($key);
    }

    /** ` class="…"` for an element of the role, with our own class first, or nothing when it has none. */
    public static function classAttribute(ElementRole $role, string $own = ''): string
    {
        return EngineFieldMarkup::classAttribute($role, $own);
    }

    /** The bold asterisk after a required field's title; hidden from screen readers, which hear `required`. */
    public static function requiredMarker(bool $required): string
    {
        return EngineFieldMarkup::requiredMarker($required);
    }

    /** The empty, hidden element the form front end puts the field's error message in. */
    public static function errorElement(string $key): string
    {
        return EngineFieldMarkup::errorElement($key);
    }

    /**
     * The answer the field opens with in the form being rendered, as its type's `FieldType::prefill()` gave
     * it (not escaped), or null: empty.
     */
    public static function prefilled(string $key): mixed
    {
        return EngineFieldMarkup::prefilled($key);
    }

    /** A title or label as authored (HTML, filtered). */
    public static function title(string $html): string
    {
        return EngineFieldMarkup::title($html);
    }

    /**
     * A string attribute of the block, or `''`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function text(array $attributes, string $name): string
    {
        return EngineFieldMarkup::text($attributes, $name);
    }

    /**
     * Whether a boolean attribute of the block is set.
     *
     * @param array<string, mixed> $attributes
     */
    public static function flag(array $attributes, string $name): bool
    {
        return EngineFieldMarkup::flag($attributes, $name);
    }

    /**
     * A field with one text control, as core's text field (`kalaka/text-field`) renders it: a `label` (the
     * title, then the required marker), an `input type="text"` that opens with the field's prefilled answer,
     * then the error element. S26's amount field is its first user. [UNAPPROVED N6]
     *
     * @param array<string, mixed> $attributes
     */
    public static function textLike(array $attributes, bool $required): string
    {
        return EngineFieldMarkup::textLike($attributes, 'text', $required);
    }
}
