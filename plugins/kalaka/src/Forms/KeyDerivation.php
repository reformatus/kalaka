<?php

declare(strict_types=1);

namespace Kalaka\Forms;

/**
 * Derives a field's key from its title and a choice option's value from its label
 * (events-and-forms.md, "Fields"): the text without tags and diacritics, lower case, every run of other
 * characters one `_`, at most 64 characters, then `_1`, `_2`, … on a collision. A key starts with a
 * letter (leading digits are dropped); a value may start with a digit. With no usable text, a key falls
 * back to the field type's name and a value to `option`.
 */
final class KeyDerivation
{
    public const KEY_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';
    public const VALUE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    private const MAX_LENGTH = 64;

    /**
     * A key for a field of the block type, unique among the taken keys.
     *
     * @param array<string, true> $taken the keys already in the form
     */
    public static function key(string $title, string $blockName, array $taken): string
    {
        $base = ltrim(self::slug($title), '0123456789_');
        if ($base === '') {
            $base = self::fallbackKey($blockName);
        }

        return self::unique($base, $taken);
    }

    /**
     * A value for an option, unique among the field's taken values.
     *
     * @param array<string, true> $taken the values already in the field
     */
    public static function value(string $label, array $taken): string
    {
        $base = self::slug($label);

        return self::unique($base === '' ? 'option' : $base, $taken);
    }

    /**
     * The text as `a-z`, `0-9` and single `_` between them. The locale is fixed, so the same text gives
     * the same key on every site (`remove_accents()` otherwise follows the site's locale).
     */
    public static function slug(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower(remove_accents($text, 'en_US'));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
    }

    /** `kalaka/email-field` → `email`; `field` when nothing usable is left. */
    private static function fallbackKey(string $blockName): string
    {
        $name = substr($blockName, (int) strrpos($blockName, '/') + (str_contains($blockName, '/') ? 1 : 0));
        $base = ltrim(self::slug((string) preg_replace('/-field\z/', '', $name)), '0123456789_');

        return $base === '' ? 'field' : $base;
    }

    /** @param array<string, true> $taken */
    private static function unique(string $base, array $taken): string
    {
        $candidate = self::cut($base, self::MAX_LENGTH);
        for ($n = 1; isset($taken[$candidate]); $n++) {
            $suffix = '_' . $n;
            $candidate = self::cut($base, self::MAX_LENGTH - strlen($suffix)) . $suffix;
        }

        return $candidate;
    }

    private static function cut(string $text, int $length): string
    {
        return rtrim(substr($text, 0, $length), '_');
    }
}
