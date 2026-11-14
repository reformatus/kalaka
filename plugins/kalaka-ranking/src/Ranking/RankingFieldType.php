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
