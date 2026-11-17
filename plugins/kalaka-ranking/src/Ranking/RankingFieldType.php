<?php

declare(strict_types=1);

namespace Kalaka\Ranking;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The module ranking (modules-and-extensions.md, "Assignment sets", request mode): the participant puts
 * some of the offered options in order of preference. Its answer is the ordered list of option keys,
 * rank 1 first, at least `min` and at most `max` of them, each an option of the block and none twice.
 * Stored as that list in JSON, like a multiple choice; for a condition it is a choice value (the order
 * is not compared), and its text numbers the option labels.
 *
 * The options are the block's own list of `{value, label}` until S44's assignment sets exist (S44-1,
 * default; re-asked in S44). Every option is offered: TODO(S76) the set's options are filtered by
 * their eligibility conditions once S44 holds them.
 */
final class RankingFieldType extends AbstractFieldType
{
    public const BLOCK_NAME = 'kalaka/ranking-field';

    /** An option key: the same rule as a choice option's value. */
    public const OPTION_KEY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    /** The fewest and most options a ranking holds when the block sets none (S45-1: min 1, max 3). */
    public const DEFAULT_MIN = 1;
    public const DEFAULT_MAX = 3;

    public const NOT_OFFERED = 'Válassz a megadott lehetőségek közül.';

    /** The drag handle's grip, shown on every ranked option (owner 2026-11-16). */
    public const HANDLE = '⠿';

    /**
     * The accessible names of an option's controls, each naming its option (`%s`, its label). The toggle
     * is the option's checkbox, drawn as „+” while unranked and „×” while ranked (owner, r2 change 4); its
     * name follows its state (`view.js` swaps it). The +/× wording is the lektor's (r2); TODO(owner): the
     * wording, or a name by the option only.
     */
    public const ADD_NAME = '%s hozzáadása a sorrendhez';
    public const REMOVE_NAME = '%s eltávolítása a sorrendből';
    public const UP_NAME = '%s feljebb mozgatása';
    public const DOWN_NAME = '%s lejjebb mozgatása';

    /**
     * The up and down buttons' icons: inline SVG paths of WordPress's own icon set (`@wordpress/icons`
     * `arrowUp`, `arrowDown`, 24 by 24, stroked in `currentColor`), so they take the theme's text colour.
     */
    public const UP_ICON = 'M12 20L12 5M17 10L12 5L7 10';
    public const DOWN_ICON = 'M12 4V19M7 14L12 19L17 14';

    /** The live counter under the ranked options: how many are ranked, of the most (owner, Q1). */
    public const COUNTER = '%1$d / %2$d kiválasztva';

    /** The first empty row's line with nothing added, and from the first addition on (owner, Q1). */
    public const MINIMUM_LINE = 'Adj hozzá legalább %s lehetőséget a sorrendhez.';
    public const MINIMUM_MORE_LINE = 'Adj hozzá még legalább %s lehetőséget a sorrendhez.';

    /** Said once to a screen reader when the order reaches the maximum (owner, Q1). */
    public const FULL = 'Megtelt a sorrend: legfeljebb %s lehetőséget választhatsz.';

    /** The two lists' names for screen readers (owner, Q4). */
    public const RANKED_NAME = 'Választott sorrend';
    public const OTHERS_NAME = 'Többi lehetőség';

    /** [UNAPPROVED N9] (Text) The empty rows' list's name for screen readers (the lektor's wording). */
    public const EMPTY_NAME = 'Üres helyek a sorrendben';

    public const TOO_FEW = 'Legalább %d lehetőséget válassz.';
    public const TOO_MANY = 'Legfeljebb %d lehetőséget válassz.';

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    /**
     * The block's option keys in authored order: each valid key once (its first occurrence); an option
     * without a valid key is left out.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    public static function optionKeys(array $attributes): array
    {
        // A key of digits only is an integer key of the array: back to the string it is.
        return array_map(strval(...), array_keys(self::labels($attributes)));
    }

    /**
     * The fewest options an answered ranking holds: the block's `min`, at least 1.
     *
     * @param array<string, mixed> $attributes
     */
    public static function min(array $attributes): int
    {
        $min = $attributes['min'] ?? self::DEFAULT_MIN;

        return is_int($min) ? max(1, $min) : self::DEFAULT_MIN;
    }

    /**
     * The most options a ranking holds: the block's `max`, never below `min`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function max(array $attributes): int
    {
        $max = $attributes['max'] ?? self::DEFAULT_MAX;

        return max(self::min($attributes), is_int($max) ? $max : self::DEFAULT_MAX);
    }

    /**
     * The order a ranking opens with, from a prefilled value (a list of keys, or a stored ranking's JSON):
     * keys the block does not offer, repeats and anything that is not a key are dropped silently, then the
     * first `max` are kept (S45-1). Too few are kept as they are: the submit asks for more.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    public static function prefilled(mixed $value, array $attributes): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $offered = self::optionKeys($attributes);
        $kept = [];
        foreach ($value as $key) {
            if (is_string($key) && in_array($key, $offered, true) && !in_array($key, $kept, true)) {
                $kept[] = $key;
            }
        }

        return array_slice($kept, 0, self::max($attributes));
    }

    /**
     * How many empty rows show under the ranked options: as many as the minimum still lacks; on an optional
     * field none until the first option is added (owner, Q2: none, or at least the minimum).
     *
     * @param array<string, mixed> $attributes
     */
    public static function emptyRows(int $ranked, array $attributes): int
    {
        $required = ($attributes['required'] ?? false) === true;

        return $required || $ranked > 0 ? max(0, self::min($attributes) - $ranked) : 0;
    }

    /** A count in the texts: „egy” for one, else the number (owner, Q1). */
    public static function amount(int $n): string
    {
        return $n === 1 ? 'egy' : (string) $n;
    }

    /**
     * The first empty row's line: with nothing added the minimum, from the first addition on how many are
     * still missing.
     *
     * @param array<string, mixed> $attributes
     */
    public static function minimumLine(int $ranked, array $attributes): string
    {
        $min = self::min($attributes);

        return $ranked === 0
            ? sprintf(self::MINIMUM_LINE, self::amount($min))
            : sprintf(self::MINIMUM_MORE_LINE, self::amount($min - $ranked));
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        $sent = $input->value;
        if (!is_array($sent) || !array_is_list($sent)) {
            throw new InvalidInput();
        }
        foreach ($sent as $key) {
            if (!is_string($key)) {
                throw new InvalidInput();
            }
        }

        $offered = self::optionKeys($input->attributes);
        if (array_diff($sent, $offered) !== [] || count(array_unique($sent)) !== count($sent)) {
            return FieldAnswer::error(self::NOT_OFFERED);
        }
        if (count($sent) < self::min($input->attributes)) {
            return FieldAnswer::error(sprintf(self::TOO_FEW, self::min($input->attributes)));
        }
        if (count($sent) > self::max($input->attributes)) {
            return FieldAnswer::error(sprintf(self::TOO_MANY, self::max($input->attributes)));
        }

        return FieldAnswer::stored((string) wp_json_encode($sent));
    }

    public function valueType(): ValueType
    {
        return ValueType::Choice;
    }

    /**
     * The data are the ranked keys, rank 1 first; the text numbers their labels, tags stripped:
     * `1. Modul C, 2. Modul A`. A key the block no longer has shows as the key.
     */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? json_decode($input->value, true) : null;
        $keys = is_array($stored) && array_is_list($stored) ? array_values(array_filter($stored, is_string(...))) : [];
        $labels = self::labels($input->attributes);

        $texts = [];
        foreach ($keys as $index => $key) {
            $texts[] = ($index + 1) . '. ' . ($labels[$key] ?? $key);
        }

        return Value::choice($keys, implode(', ', $texts));
    }

    /**
     * The block's options as key → label (tags stripped), in authored order, each valid key once.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, string>
     */
    public static function labels(array $attributes): array
    {
        $options = is_array($attributes['options'] ?? null) ? $attributes['options'] : [];
        $labels = [];
        foreach ($options as $option) {
            $key = is_array($option) ? ($option['value'] ?? null) : null;
            if (
                is_string($key) && preg_match(self::OPTION_KEY_PATTERN, $key) === 1
                && !array_key_exists($key, $labels)
            ) {
                $label = $option['label'] ?? '';
                $labels[$key] = wp_strip_all_tags(is_string($label) ? $label : '');
            }
        }

        return $labels;
    }
}
