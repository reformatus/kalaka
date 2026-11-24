<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * One ad hoc filter of the grid on a column (S37), validated when it is made: the operator must belong to the
 * kind and the value must have the kind's shape. The value is canonical afterwards: `list<string>` for
 * `Members`, a string for `Text`, `Number` (a decimal) and `Date` (`Y-m-d`), `list{string, string}` for a
 * `between`, a bool for `YesNo`. `Members` and `YesNo` may also name `NO_ANSWER`: no value, no record.
 */
final readonly class ColumnFilter
{
    /**
     * „Nincs válasz” as a value of a list column's own filter (a choice, a yes/no, a decision): the rows with no
     * value (a field) or no record (a decision). Never a stored value. [UNAPPROVED N66]
     */
    public const NO_ANSWER = 'kalaka:no-answer';

    /** @param string|bool|list<string> $value canonical, see above */
    private function __construct(
        public string $column,
        public FilterKind $kind,
        public string $operator,
        public string|bool|array $value,
    ) {
    }

    /**
     * @throws InvalidInput on an operator that is not the kind's or a value that is not its shape
     */
    public static function of(string $column, FilterKind $kind, string $operator, mixed $value): self
    {
        $value = match ($kind) {
            FilterKind::Members => self::list($operator, ['isAny', 'isNone'], $value),
            FilterKind::Text => self::text($operator, $value),
            FilterKind::Number => $operator === 'between'
                ? self::pair($value)
                : self::number($operator, ['is', 'lessThan', 'greaterThan'], $value),
            FilterKind::Date => self::date($operator, $value),
            FilterKind::YesNo => self::yesNo($operator, $value),
        };

        return new self($column, $kind, $operator, $value);
    }

    /**
     * @param list<string> $operators
     * @return list<string>
     */
    private static function list(string $operator, array $operators, mixed $value): array
    {
        if (!in_array($operator, $operators, true) || !is_array($value) || !array_is_list($value)) {
            throw new InvalidInput();
        }
        if ($value === [] || count($value) > Narrowing::MAX_VALUES) {
            throw new InvalidInput();
        }
        $values = [];
        foreach ($value as $one) {
            if (!is_string($one) || $one === '' || mb_strlen($one, 'UTF-8') > Narrowing::MAX_VALUE_LENGTH) {
                throw new InvalidInput();
            }
            $values[$one] = $one;
        }

        return array_values($values);
    }

    private static function text(string $operator, mixed $value): string
    {
        if ($operator !== 'contains' || !is_string($value)) {
            throw new InvalidInput();
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > Narrowing::MAX_SEARCH) {
            throw new InvalidInput();
        }

        return $value;
    }

    /** @param list<string> $operators */
    private static function number(string $operator, array $operators, mixed $value): string
    {
        if (!in_array($operator, $operators, true)) {
            throw new InvalidInput();
        }

        return self::decimal($value);
    }

    /** A number or numeric string of at most 15 integer digits and 8 decimals, as a decimal string. */
    private static function decimal(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,8})?$/D', $value) !== 1) {
            throw new InvalidInput();
        }

        return $value === '-0' ? '0' : $value;
    }

    /** @return list{string, string} */
    private static function pair(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            throw new InvalidInput();
        }

        return [self::decimal($value[0]), self::decimal($value[1])];
    }

    private static function date(string $operator, mixed $value): string
    {
        if (!in_array($operator, ['before', 'after'], true) || !is_string($value)) {
            throw new InvalidInput();
        }
        $day = substr($value, 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new InvalidInput();
        }

        return $day;
    }

    private static function yesNo(string $operator, mixed $value): bool|string
    {
        if (!in_array($operator, ['is', 'isNot'], true) || !(is_bool($value) || $value === self::NO_ANSWER)) {
            throw new InvalidInput();
        }

        return $value;
    }
}
