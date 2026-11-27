<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Reads a condition in the stored format (events-and-forms.md, "Conditions") into a `Condition`.
 * Pure: the scope says which keys may be read and of which type. Any problem is a definition
 * problem (`SystemFailure`) naming the problem code and, where there is one, the key; never a
 * value or a label.
 */
final class ConditionParser
{
    /** @throws SystemFailure */
    public function parse(mixed $data, ConditionScope $scope): Condition
    {
        return $this->group($data, $scope, true);
    }

    /** @throws SystemFailure */
    private function group(mixed $data, ConditionScope $scope, bool $top): Condition
    {
        if (!$this->isObject($data)) {
            self::fail();
        }
        if (array_diff(array_keys($data), $top ? ['match', 'items'] : ['match', 'items', 'not']) !== []) {
            self::fail();
        }
        $match = is_string($data['match'] ?? null) ? MatchMode::tryFrom($data['match']) : null;
        $items = $data['items'] ?? null;
        $not = $data['not'] ?? false;
        if ($match === null || !is_array($items) || $items === [] || !array_is_list($items) || !is_bool($not)) {
            self::fail();
        }

        $parsed = [];
        foreach ($items as $item) {
            if (!$this->isObject($item)) {
                self::fail();
            }
            $isComparison = array_key_exists('operator', $item);
            $isGroup = array_key_exists('items', $item);
            if ($isComparison === $isGroup || ($isGroup && !$top)) {
                self::fail();
            }
            $parsed[] = $isComparison ? $this->comparison($item, $scope) : $this->group($item, $scope, false);
        }

        return new Condition($match, $parsed, $not);
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws SystemFailure
     */
    private function comparison(array $data, ConditionScope $scope): Comparison
    {
        if (array_diff(array_keys($data), ['key', 'operator', 'value', 'not']) !== []) {
            self::fail();
        }
        $key = $data['key'] ?? null;
        $operator = is_string($data['operator'] ?? null) ? Operator::tryFrom($data['operator']) : null;
        $not = $data['not'] ?? false;
        if (!is_string($key) || $operator === null || !is_bool($not)) {
            self::fail();
        }

        // `time.now` as the left key: the moment itself, a datetime in every scope (a dotted fact
        // key, so no field can be named like it); a value-side reference to it stays `{"ref": "now"}`.
        $type = $key === Facts::NOW ? ValueType::Datetime : $scope->typeOf($key);
        if (!$operator->isAllowedFor($type) || ($operator === Operator::Equals && $scope->isPath($key))) {
            self::fail($key);
        }
        if ($operator === Operator::Empty) {
            if (array_key_exists('value', $data)) {
                self::fail($key);
            }

            return new Comparison($key, $operator, null, $not);
        }

        $value = $data['value'] ?? null;
        if (($type === ValueType::Date || $type === ValueType::Datetime) && $this->isObject($value)) {
            return new Comparison($key, $operator, null, $not, $this->reference($value, $scope, $key));
        }
        if (!is_string($value) || !$this->isWellFormed($type, $value)) {
            self::fail($key);
        }
        $choices = $type === ValueType::Choice ? $scope->choices($key) : null;
        if ($choices !== null && !in_array($value, $choices, true)) {
            self::fail($key);
        }

        return new Comparison($key, $operator, $value, $not);
    }

    /**
     * A date reference: `{"key", "years", "days"}`, the key a date or datetime the scope allows, or
     * `{"ref": "now", "years",
     * "days"}` (no key: the evaluation's moment, `Facts::NOW`; a `key` naming `Facts::NOW` itself is refused), the
     * moves whole
     * numbers (both optional) of at most `DateReference::MAX_YEARS` years and `MAX_DAYS` days either way.
     * Decision N3 (#156): the stored shape of a date reference in a comparison.
     *
     * @param array<array-key, mixed> $data
     * @throws SystemFailure
     */
    private function reference(array $data, ConditionScope $scope, string $key): DateReference
    {
        $now = array_key_exists('ref', $data);
        if (array_diff(array_keys($data), [$now ? 'ref' : 'key', 'years', 'days']) !== []) {
            self::fail($key);
        }
        $reference = $now ? $data['ref'] : ($data['key'] ?? null);
        $years = $data['years'] ?? 0;
        $days = $data['days'] ?? 0;
        if (!is_string($reference) || !is_int($years) || !is_int($days) || ($now && $reference !== 'now')) {
            self::fail($key);
        }
        if (abs($years) > DateReference::MAX_YEARS || abs($days) > DateReference::MAX_DAYS) {
            self::fail($key);
        }
        if ($now) {
            return DateReference::now($years, $days);
        }
        $referenced = $reference === Facts::NOW ? null : $scope->typeOf($reference);
        if ($referenced !== ValueType::Date && $referenced !== ValueType::Datetime) {
            self::fail($key);
        }

        return new DateReference($reference, $years, $days);
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $data */
    private function isObject(mixed $data): bool
    {
        return is_array($data) && ($data === [] || !array_is_list($data));
    }

    private function isWellFormed(ValueType $type, string $value): bool
    {
        try {
            match ($type) {
                ValueType::Number => Value::number($value, ''),
                ValueType::Date, ValueType::Datetime => strlen($value) === 10
                    ? Value::date($value, '')
                    : Value::datetime($value, ''),
                ValueType::Text => null,
                ValueType::Choice => $value !== '' ? null : throw new \InvalidArgumentException(),
            };
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function fail(?string $key = null): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
        throw new SystemFailure('invalid_condition' . ($key !== null ? ' ' . $key : ''));
    }
}
