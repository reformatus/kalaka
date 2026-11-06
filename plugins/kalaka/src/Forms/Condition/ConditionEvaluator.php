<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Vendor\Brick\Math\BigDecimal;

/**
 * Decides whether a parsed condition holds for a value context. Pure: no database, hook, user,
 * request or clock, so a caller inside a transaction can use it.
 *
 * An empty value makes every operator but `empty` false; `not` then inverts the result.
 */
final class ConditionEvaluator
{
    /** @throws SystemFailure when a key is not declared or an operator does not fit the value's type */
    public function holds(Condition $condition, ValueContext $context): bool
    {
        $results = [];
        foreach ($condition->items as $item) {
            $results[] = $item instanceof Condition ? $this->holds($item, $context) : $this->compares($item, $context);
        }
        $holds = $condition->match === MatchMode::All
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);

        return $condition->not ? !$holds : $holds;
    }

    /** @throws SystemFailure */
    private function compares(Comparison $comparison, ValueContext $context): bool
    {
        $value = $context->get($comparison->key);
        if (!$comparison->operator->isAllowedFor($value->type)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_condition ' . $comparison->key);
        }

        if ($comparison->operator === Operator::Empty) {
            $result = $value->isEmpty();
        } elseif ($value->isEmpty() || $comparison->value === null) {
            $result = false;
        } else {
            $result = match ($value->type) {
                ValueType::Number => $this->ordered(
                    BigDecimal::of($this->string($value))->compareTo($comparison->value),
                    $comparison->operator
                ),
                ValueType::Date => $this->ordered(
                    strcmp($this->string($value), $comparison->value),
                    $comparison->operator
                ),
                ValueType::Text => $this->text($value, $comparison),
                ValueType::Choice => $this->choice($value, $comparison),
            };
        }

        return $comparison->not ? !$result : $result;
    }

    private function string(Value $value): string
    {
        return is_string($value->data) ? $value->data : '';
    }

    /** @param int $order negative, zero or positive: the value against the given one */
    private function ordered(int $order, Operator $operator): bool
    {
        return match ($operator) {
            Operator::Equals => $order === 0,
            Operator::LessThan => $order < 0,
            Operator::LessOrEqual => $order <= 0,
            Operator::GreaterThan => $order > 0,
            Operator::GreaterOrEqual => $order >= 0,
            default => false,
        };
    }

    /** Case-insensitive, accent-sensitive. */
    private function text(Value $value, Comparison $comparison): bool
    {
        $text = mb_strtolower($this->string($value));
        $given = mb_strtolower((string) $comparison->value);

        return $comparison->operator === Operator::Contains ? str_contains($text, $given) : $text === $given;
    }

    private function choice(Value $value, Comparison $comparison): bool
    {
        $chosen = is_array($value->data) ? $value->data : [];

        return $comparison->operator === Operator::Contains
            ? in_array($comparison->value, $chosen, true)
            : $chosen === [$comparison->value];
    }
}
