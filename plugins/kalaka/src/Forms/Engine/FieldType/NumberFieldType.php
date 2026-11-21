<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Vendor\Brick\Math\BigDecimal;

/**
 * A number (`kalaka/number-field`): a whole or decimal number, at most `DIGITS` integer digits and
 * `MAX_DECIMALS` decimals, with the block's optional `min`, `step` and `max`. The step counts from `min`
 * when one is set, else from 0, as the native arrows do. Its value is a `Number`; its text groups the
 * digits with no-break spaces, writes the fraction after a comma, and carries the block's optional
 * prefix and suffix (`5 000 Ft`). A negative number uses U+2212. [UNAPPROVED T4]
 */
final class NumberFieldType extends AbstractFieldType
{
    public const BLOCK_NAME = 'kalaka/number-field';

    /** At most this many integer digits. */
    public const DIGITS = 12;

    public const MAX_DECIMALS = 8;

    /** A decimal as sent or stored: an optional minus, digits, and an optional fraction. */
    private const DECIMAL = '/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/';

    private const NBSP = "\u{00A0}";

    private const MINUS = "\u{2212}";

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    /**
     * The settings the event's participant field keeps, collected from the block on save. An invalid
     * setting is DefinitionParser's `invalid_number`; a lenient read gives none.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function settings(array $attributes): array
    {
        return self::readSettings($attributes) ?? [];
    }

    /**
     * The block's settings, or null when they are not valid: `decimals` 0 to `MAX_DECIMALS`; `min`, `step`
     * and `max` decimals within that precision (and `step` above 0); `min` not above `max`; `prefix` and
     * `suffix` text.
     *
     * @param array<string, mixed> $attributes
     * @return array{min: ?string, step: ?string, max: ?string, decimals: int, prefix: string, suffix: string}|null
     */
    public static function readSettings(array $attributes): ?array
    {
        $decimals = $attributes['decimals'] ?? 0;
        if (is_string($decimals) && preg_match('/\A[0-9]\z/', $decimals) === 1) {
            $decimals = (int) $decimals;
        }
        if (!is_int($decimals) || $decimals < 0 || $decimals > self::MAX_DECIMALS) {
            return null;
        }
        $min = self::bound($attributes['min'] ?? null, $decimals);
        $step = self::bound($attributes['step'] ?? null, $decimals);
        $max = self::bound($attributes['max'] ?? null, $decimals);
        if ($min === false || $step === false || $max === false) {
            return null;
        }
        if ($step !== null && !BigDecimal::of($step)->isPositive()) {
            return null;
        }
        if ($min !== null && $max !== null && BigDecimal::of($min)->isGreaterThan(BigDecimal::of($max))) {
            return null;
        }
        $prefix = $attributes['prefix'] ?? '';
        $suffix = $attributes['suffix'] ?? '';
        if (!is_string($prefix) || !is_string($suffix)) {
            return null;
        }

        return [
            'min' => $min,
            'step' => $step,
            'max' => $max,
            'decimals' => $decimals,
            'prefix' => trim($prefix),
            'suffix' => trim($suffix),
        ];
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $settings = self::readSettings($input->attributes);
        if ($settings === null) {
            throw new InvalidInput();
        }
        $text = self::trimmed($input->value);
        if ($text === '') {
            return FieldAnswer::notAnswered();
        }
        [$canonical, $problem] = self::parse($text, $settings['decimals']);
        if ($canonical === null) {
            if ($settings['decimals'] === 0 && $problem !== 'exponent') {
                // [UNAPPROVED T4]
                return FieldAnswer::error('Csak egész számot írhatsz ide.');
            }
            // [UNAPPROVED T4]
            $message = $problem === 'decimals'
                ? sprintf('Legfeljebb %d tizedesjegyet írhatsz ide.', $settings['decimals'])
                : 'Csak számot írhatsz ide.';

            return FieldAnswer::error($message);
        }

        $value = BigDecimal::of($canonical);
        if ($settings['min'] !== null && $value->isLessThan(BigDecimal::of($settings['min']))) {
            // [UNAPPROVED T4]
            return FieldAnswer::error('Legalább ' . self::text($settings['min'], $settings) . ' legyen.');
        }
        if ($settings['step'] !== null && !self::onStep($value, $settings['min'], $settings['step'])) {
            return FieldAnswer::error(self::stepMessage($settings));
        }
        if ($settings['max'] !== null && $value->isGreaterThan(BigDecimal::of($settings['max']))) {
            // [UNAPPROVED T4]
            return FieldAnswer::error('Legfeljebb ' . self::text($settings['max'], $settings) . ' legyen.');
        }

        return FieldAnswer::stored($canonical);
    }

    public function valueType(): ValueType
    {
        return ValueType::Number;
    }

    /**
     * The stored number as a `Number`, with its text. A stored value that is not a number reads as none.
     * The prefix and suffix come from the participant field's settings.
     */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';
        [$canonical] = self::parse($stored, self::MAX_DECIMALS);
        if ($canonical === null) {
            return Value::none(ValueType::Number);
        }
        $settings = self::readSettings($input->attributes) ?? [
            'min' => null,
            'step' => null,
            'max' => null,
            'decimals' => 0,
            'prefix' => '',
            'suffix' => '',
        ];

        return Value::number($canonical, self::text($canonical, $settings));
    }

    /** The stored number when the current settings accept it as an answer; otherwise the field opens empty. */
    public function prefill(FieldTypeInput $input): mixed
    {
        $stored = is_string($input->value) ? $input->value : '';
        $settings = self::readSettings($input->attributes);
        if ($settings === null) {
            return null;
        }
        [$canonical] = self::parse($stored, $settings['decimals']);
        if ($canonical === null) {
            return null;
        }
        $value = BigDecimal::of($canonical);
        if ($settings['min'] !== null && $value->isLessThan(BigDecimal::of($settings['min']))) {
            return null;
        }
        if ($settings['step'] !== null && !self::onStep($value, $settings['min'], $settings['step'])) {
            return null;
        }
        if ($settings['max'] !== null && $value->isGreaterThan(BigDecimal::of($settings['max']))) {
            return null;
        }

        return $canonical;
    }

    /**
     * A limit as a canonical decimal, null when it is not set, or false when it is not valid: a decimal,
     * within `$decimals` places and `DIGITS` integer digits.
     *
     * @return string|null|false
     */
    private static function bound(mixed $value, int $decimals): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        [$canonical, $problem] = self::parse($value, $decimals);

        return $canonical !== null && $problem === null ? $canonical : false;
    }

    /**
     * A decimal as a canonical string (no leading zeros, no trailing fractional zeros, `-0` as `0`), and
     * the problem when it is not one: `number`, or too many `decimals`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function parse(string $text, int $decimals): array
    {
        if (preg_match('/[eE]/', $text) === 1) {
            // HTML's exponent notation is refused; only a plain decimal is a number here.
            return [null, 'exponent'];
        }
        if (preg_match('/\A(-?)\.([0-9]+)\z/', $text, $leading) === 1) {
            $text = $leading[1] . '0.' . $leading[2];
        }
        if (preg_match(self::DECIMAL, $text, $parts) !== 1) {
            return [null, 'number'];
        }
        $fraction = $parts[3] ?? '';
        if ($fraction !== '' && strlen($fraction) > $decimals) {
            return [null, 'decimals'];
        }
        $whole = ltrim($parts[2], '0');
        if ($whole === '') {
            $whole = '0';
        }
        if (strlen($whole) > self::DIGITS) {
            return [null, 'number'];
        }
        $fraction = rtrim($fraction, '0');
        $negative = $parts[1] === '-' && ($whole !== '0' || $fraction !== '');

        return [
            ($negative ? '-' : '') . $whole . ($fraction === '' ? '' : '.' . $fraction),
            null,
        ];
    }

    /** Whether a value sits on the step grid, counted from `min` when it is set, else from 0. */
    private static function onStep(BigDecimal $value, ?string $min, string $step): bool
    {
        $base = BigDecimal::of($min ?? '0');
        $remainder = $value->minus($base)->remainder(BigDecimal::of($step));

        return $remainder->isZero();
    }

    /**
     * The step refusal: with a minimum, the first three values of its grid as bare numbers; without one,
     * the multiple's text.
     *
     * @param array{min: ?string, step: ?string, max: ?string, decimals: int, prefix: string, suffix: string} $settings
     */
    private static function stepMessage(array $settings): string
    {
        \assert($settings['step'] !== null);
        if ($settings['min'] === null) {
            // [UNAPPROVED T4]
            return 'Az érték ' . self::text($settings['step'], $settings) . ' többszöröse legyen.';
        }
        $min = BigDecimal::of($settings['min']);
        $step = BigDecimal::of($settings['step']);
        $examples = [];
        foreach ([0, 1, 2] as $index) {
            $example = $min->plus($step->multipliedBy($index))->toString();
            [$canonical] = self::parse($example, self::MAX_DECIMALS);
            $examples[] = self::plain($canonical ?? '0');
        }

        // [UNAPPROVED T4]
        return sprintf(
            'Csak ilyen értéket írhatsz ide: %s, %s, %s és így tovább.',
            $examples[0],
            $examples[1],
            $examples[2],
        );
    }

    /**
     * A number's text: grouped digits, a comma before the fraction, the prefix before and the suffix after,
     * separated by a no-break space (T5-approved).
     *
     * @param array{min: ?string, step: ?string, max: ?string, decimals: int, prefix: string, suffix: string} $settings
     */
    private static function text(string $canonical, array $settings): string
    {
        $number = self::plain($canonical);
        if ($settings['prefix'] !== '') {
            $number = $settings['prefix'] . self::NBSP . $number;
        }
        if ($settings['suffix'] !== '') {
            $number .= self::NBSP . $settings['suffix'];
        }

        return $number;
    }

    /** A canonical number with its digits grouped and U+2212 for a negative; no prefix or suffix. */
    private static function plain(string $canonical): string
    {
        $negative = str_starts_with($canonical, '-');
        $unsigned = ltrim($canonical, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = (string) preg_replace('/\B(?=(?:[0-9]{3})+\z)/', self::NBSP, $whole);
        $text = $whole . ($fraction === '' ? '' : ',' . $fraction);

        return ($negative ? self::MINUS : '') . $text;
    }
}
