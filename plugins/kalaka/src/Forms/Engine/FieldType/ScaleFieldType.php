<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * One point of a scale from `min` (default 1) to `max` (default 5), 2 to 11 points in all, each with an
 * optional label (`pointLabels`, a list from `min` on). Sent and stored as the integer's string; its value is
 * a number, its text the number and, when the point has one, its label (`5 – inkább egyetértek`).
 */
final class ScaleFieldType extends AbstractFieldType
{
    public const BLOCK_NAME = 'kalaka/scale-field';

    public const DEFAULT_MIN = 1;
    public const DEFAULT_MAX = 5;
    public const MIN_POINTS = 2;
    public const MAX_POINTS = 11;

    /** The farthest a point may be from 0, as the editor keeps it (`range.js`), so every point can be sent. */
    public const LIMIT = 100;

    /** Between the number and the point's label in the value's text. */
    public const SEPARATOR = ' – ';

    /** An integer as the browser sends it and as it is stored: no sign on 0, no leading zero. */
    private const INTEGER_PATTERN = '/\A(0|-?[1-9][0-9]{0,8})\z/';

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    /**
     * The scale's first and last point, or null when its settings are not valid: `min` and `max` integers
     * (1 and 5 when absent) within ±`LIMIT`, with 2 to 11 points from one to the other.
     *
     * @param array<string, mixed> $attributes
     * @return array{int, int}|null
     */
    public static function range(array $attributes): ?array
    {
        $min = $attributes['min'] ?? self::DEFAULT_MIN;
        $max = $attributes['max'] ?? self::DEFAULT_MAX;
        if (!is_int($min) || !is_int($max) || $min < -self::LIMIT || $max > self::LIMIT) {
            return null;
        }
        $points = $max - $min + 1;

        return $points >= self::MIN_POINTS && $points <= self::MAX_POINTS ? [$min, $max] : null;
    }

    /**
     * The label of a point, tags stripped, or `''` when it has none.
     *
     * @param array<string, mixed> $attributes
     */
    public static function pointLabel(array $attributes, int $point): string
    {
        $min = $attributes['min'] ?? self::DEFAULT_MIN;
        $labels = $attributes['pointLabels'] ?? null;
        if (!is_int($min) || !is_array($labels) || !array_is_list($labels)) {
            return '';
        }
        $label = $labels[$point - $min] ?? null;

        return is_string($label) ? trim(wp_strip_all_tags($label)) : '';
    }

    /**
     * A point of the scale, as its integer, or null when the text is not one.
     *
     * @param array<string, mixed> $attributes
     */
    private static function point(mixed $sent, array $attributes): ?int
    {
        $range = self::range($attributes);
        if (!is_string($sent) || $range === null || preg_match(self::INTEGER_PATTERN, $sent) !== 1) {
            return null;
        }
        $point = (int) $sent;

        return $point >= $range[0] && $point <= $range[1] ? $point : null;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $point = self::point($input->value, $input->attributes);

        // The choice's text, reused for a point that is not on the scale.
        return $point === null
            ? FieldAnswer::error('Válassz a megadott lehetőségek közül.')
            : FieldAnswer::stored((string) $point);
    }

    /**
     * The stored point when it is one of the scale's points; anything else opens the field empty, as a
     * choice that is not offered does.
     */
    public function prefill(FieldTypeInput $input): mixed
    {
        $point = self::point($input->value, $input->attributes);

        return $point === null ? null : (string) $point;
    }

    public function valueType(): ValueType
    {
        return ValueType::Number;
    }

    /**
     * The stored integer as a number; its text adds the point's label when the attributes give one. A stored
     * string that is not an integer is no value (`none`).
     */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';
        if (preg_match(self::INTEGER_PATTERN, $stored) !== 1) {
            return Value::none(ValueType::Number);
        }
        $label = self::pointLabel($input->attributes, (int) $stored);

        return Value::number($stored, $label === '' ? $stored : $stored . self::SEPARATOR . $label);
    }
}
