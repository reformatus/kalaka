<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Vendor\Brick\Math\BigDecimal;

/**
 * Decides whether a parsed condition holds for a fact source. Pure: no database, hook, user,
 * request or clock, so a caller inside a transaction can use it. It is the one evaluator behind
 * every availability (S76), reached through `Api\Availability` and the form walk.
 *
 * An empty value makes every operator but `empty` false, and so does a date reference to an empty
 * date; `not` then inverts the result.
 */
final class ConditionEvaluator
{
    /** @throws SystemFailure when a key is not declared or an operator does not fit the value's type */
    public function holds(Condition $condition, Facts $context): bool
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
    private function compares(Comparison $comparison, Facts $context): bool
    {
        $value = $context->get($comparison->key);
        if (!$comparison->operator->isAllowedFor($value->type)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_condition ' . $comparison->key);
        }

        $given = $this->given($comparison, $context);
        if ($comparison->operator === Operator::Empty) {
            $result = $value->isEmpty();
        } elseif ($value->isEmpty() || $given === null) {
            $result = false;
        } else {
            $result = match ($value->type) {
                ValueType::Number => $this->ordered(
                    BigDecimal::of($this->string($value))->compareTo($given),
                    $comparison->operator
                ),
                ValueType::Date => $this->ordered(strcmp($this->string($value), $given), $comparison->operator),
                ValueType::Text => $this->text($value, $comparison),
                ValueType::Choice => $this->choice($value, $comparison),
            };
        }

        return $comparison->not ? !$result : $result;
    }

    /**
     * What the value is compared with: the fixed value, or the referenced date moved; null for
     * `empty` and for a reference to an empty date.
     *
     * @throws SystemFailure a reference to an undeclared key or to a key that is not a date
     */
    private function given(Comparison $comparison, Facts $context): ?string
    {
        if ($comparison->reference === null) {
            return $comparison->value;
        }
        $referenced = $context->get($comparison->reference->key);
        if ($referenced->type !== ValueType::Date) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_condition ' . $comparison->key);
        }

        return $referenced->isEmpty() ? null : $comparison->reference->from($this->string($referenced));
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

    /** Case-insensitive by Unicode case folding (so `ß` matches `SS`), accent-sensitive. */
    private function text(Value $value, Comparison $comparison): bool
    {
        $text = mb_convert_case($this->string($value), MB_CASE_FOLD, 'UTF-8');
        $given = mb_convert_case((string) $comparison->value, MB_CASE_FOLD, 'UTF-8');

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
