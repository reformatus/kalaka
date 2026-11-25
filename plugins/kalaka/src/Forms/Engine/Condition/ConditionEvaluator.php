<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

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
 * Dates and datetimes compare by what the two operands are: two datetimes as instants (offsets differ across a
 * daylight-saving change, so never as strings); a day against anything else on the day, a datetime
 * contributing the day it was written on (the site's zone), never midnight. So `now >= D` holds from the start of
 * D and `now <= D` to its end (events-and-forms.md, "Conditions").
 *
 * Three values: true, false and unknown (null). A comparison on a missing value, or against a
 * reference to a missing date, is unknown: nothing was answered, so the comparison cannot be
 * judged. Only `empty` reads a missing value (`empty` true, `not empty` false). `not` never turns
 * unknown into anything: not unknown is unknown. At the top, a condition holds only when it is
 * true; unknown counts as not matched.
 *
 *     all:  any false -> false;  else any unknown -> unknown;  else true
 *     any:  any true  -> true;   else any unknown -> unknown;  else false
 *     not:  true -> false, false -> true, unknown -> unknown
 *
 * So `not equals X` does not include someone who never answered; an author who wants them writes
 * `empty` or `not equals X`.
 */
final class ConditionEvaluator
{
    /** @throws SystemFailure when a key is not declared or an operator does not fit the value's type */
    public function holds(Condition $condition, Facts $context): bool
    {
        return $this->evaluate($condition, $context) === true;
    }

    /**
     * @return bool|null null is unknown
     * @throws SystemFailure
     */
    private function evaluate(Condition $condition, Facts $context): ?bool
    {
        $results = [];
        foreach ($condition->items as $item) {
            $results[] = $item instanceof Condition
                ? $this->evaluate($item, $context)
                : $this->compares($item, $context);
        }
        if ($condition->match === MatchMode::All) {
            $holds = in_array(false, $results, true) ? false : (in_array(null, $results, true) ? null : true);
        } else {
            $holds = in_array(true, $results, true) ? true : (in_array(null, $results, true) ? null : false);
        }

        return $condition->not && $holds !== null ? !$holds : $holds;
    }

    /**
     * @return bool|null null is unknown
     * @throws SystemFailure
     */
    private function compares(Comparison $comparison, Facts $context): ?bool
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
            return null;
        } else {
            $result = match ($value->type) {
                ValueType::Number => $this->ordered(
                    BigDecimal::of($this->string($value))->compareTo($given),
                    $comparison->operator
                ),
                ValueType::Date, ValueType::Datetime => $this->ordered(
                    $this->moments($this->string($value), $given),
                    $comparison->operator
                ),
                ValueType::Text => $this->text($value, $comparison),
                ValueType::Choice => $this->choice($value, $comparison),
            };
        }

        return $comparison->not ? !$result : $result;
    }

    /**
     * What the value is compared with: the fixed value, or the referenced date or datetime moved; null for
     * `empty` and for a reference to an empty date.
     *
     * @throws SystemFailure a reference to an undeclared key or to a key that is neither a date nor a datetime
     */
    private function given(Comparison $comparison, Facts $context): ?string
    {
        if ($comparison->reference === null) {
            return $comparison->value;
        }
        $referenced = $context->get($comparison->reference->key);
        if ($referenced->type !== ValueType::Date && $referenced->type !== ValueType::Datetime) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_condition ' . $comparison->key);
        }
        if ($referenced->isEmpty()) {
            return null;
        }

        return $referenced->type === ValueType::Datetime
            ? $comparison->reference->fromInstant($referenced)
            : $comparison->reference->from($this->string($referenced));
    }

    /**
     * Two dates or datetimes ordered: a day (`YYYY-MM-DD`) is ten characters, an instant longer. Two instants
     * compare as instants; otherwise the days, an instant's day being its date part as written (decision N652,
     * #222).
     */
    private function moments(string $value, string $given): int
    {
        if (strlen($value) > 10 && strlen($given) > 10) {
            $left = Value::instant($value);
            $right = Value::instant($given);
            if ($left === null || $right === null) {
                throw new SystemFailure('invalid_condition');
            }

            return $left <=> $right;
        }

        return strcmp(substr($value, 0, 10), substr($given, 0, 10));
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
