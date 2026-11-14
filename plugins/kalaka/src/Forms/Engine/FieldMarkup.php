<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldWrapper;

/**
 * The shared pieces of the field blocks' `render.php`: the wrapper, the classes of each element role
 * (core's own classes, then the theme adapter's), the required marker and the error element.
 * Every string it returns is already escaped. Markup is semantic and uses no class of ours beyond
 * `kalaka-field`, `kalaka-field__required` and `kalaka-field__error`; the CSS that targets them is structural
 * (engineering.md, "WordPress surfaces").
 */
final class FieldMarkup
{
    /**
     * The HTML autofill tokens a date field may carry (`kalaka/date-field`'s `autocomplete` attribute).
     * The autofill hints: name `name`, email `email`, phone `tel`, a date's own.
     */
    public const DATE_AUTOCOMPLETE = ['bday'];

    /** The id of a field's control, which its `label` names. */
    public static function controlId(string $key): string
    {
        return 'kalaka-field-' . $key;
    }

    /**
     * The wrapper element: `p` when the adapter chooses it, but only for a single text control. A
     * choice's `fieldset` cannot sit in a `p`, and a consent stays a `div` too (themes such as Divi
     * hide `p input[type=checkbox]`).
     */
    public static function wrapperTag(bool $singleControl): string
    {
        return $singleControl && ThemeAdapters::current()->wrapper === FieldWrapper::P ? 'p' : 'div';
    }

    /**
     * The wrapper's attributes: the block's own (`wp-block-kalaka-…`, and what the block supports add),
     * `kalaka-field`, the adapter's field classes and `data-kalaka-field`.
     */
    public static function wrapperAttributes(string $key): string
    {
        return get_block_wrapper_attributes([
            'class' => implode(' ', ['kalaka-field', ...ThemeAdapters::current()->classes(ElementRole::Field)]),
            'data-kalaka-field' => $key,
        ]);
    }

    /** ` class="…"` for an element of the role, with our own class first, or nothing when it has none. */
    public static function classAttribute(ElementRole $role, string $own = ''): string
    {
        $classes = ThemeAdapters::current()->classes($role);
        if ($own !== '') {
            array_unshift($classes, $own);
        }

        return $classes === [] ? '' : ' class="' . esc_attr(implode(' ', $classes)) . '"';
    }

    /** The bold asterisk after a required field's title; hidden from screen readers, which hear `required`. */
    public static function requiredMarker(bool $required): string
    {
        return $required
            ? '<span' . self::classAttribute(ElementRole::RequiredMarker, 'kalaka-field__required')
                . ' aria-hidden="true">*</span>'
            : '';
    }

    /** The empty, hidden element the form front end puts the field's error message in. */
    public static function errorElement(string $key): string
    {
        return '<span id="' . esc_attr(self::controlId($key) . '-error') . '"'
            . self::classAttribute(ElementRole::ErrorMessage, 'kalaka-field__error') . ' hidden></span>';
    }

    /** The field's title as authored (HTML, filtered). */
    public static function title(string $html): string
    {
        return wp_kses_post($html);
    }

    /**
     * A string attribute of the block, or `''`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function text(array $attributes, string $name): string
    {
        return is_string($attributes[$name] ?? null) ? $attributes[$name] : '';
    }

    /**
     * Whether a boolean attribute of the block is set.
     *
     * @param array<string, mixed> $attributes
     */
    public static function flag(array $attributes, string $name): bool
    {
        return ($attributes[$name] ?? false) === true;
    }

    /**
     * A field with one text-like control: a `label` (the title, then the required marker), the `input` of
     * the given type or a `textarea`, then the error element. `$autocomplete` is the control's autofill
     * hint (an HTML autofill token), or null for none.
     *
     * @param array<string, mixed> $attributes
     */
    public static function textLike(
        array $attributes,
        string $inputType,
        bool $required,
        bool $multiline = false,
        ?string $autocomplete = null
    ): string {
        $key = self::text($attributes, 'key');
        $id = esc_attr(self::controlId($key));
        $controlAttributes = ' id="' . $id . '" name="' . esc_attr($key) . '"'
            . ($autocomplete === null ? '' : ' autocomplete="' . esc_attr($autocomplete) . '"')
            . self::classAttribute(ElementRole::TextControl) . ($required ? ' required' : '');
        $control = $multiline
            ? '<textarea' . $controlAttributes . '></textarea>'
            : '<input type="' . esc_attr($inputType) . '"' . $controlAttributes . '>';
        $tag = self::wrapperTag(true);

        return '<' . $tag . ' ' . self::wrapperAttributes($key) . '>'
            . '<label for="' . $id . '"' . self::classAttribute(ElementRole::Label) . '>'
            . self::title(self::text($attributes, 'label')) . self::requiredMarker($required) . '</label>'
            . $control
            . self::errorElement($key)
            . '</' . $tag . '>';
    }

    /**
     * The date field's autofill hint: its `autocomplete` attribute when it is one the block allows, else none.
     *
     * @param array<string, mixed> $attributes
     */
    public static function dateAutocomplete(array $attributes): ?string
    {
        $token = $attributes['autocomplete'] ?? null;

        return in_array($token, self::DATE_AUTOCOMPLETE, true) ? $token : null;
    }
}
