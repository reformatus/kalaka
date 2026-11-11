<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\ContextScope;
use Kalaka\Forms\Condition\DeclaredScope;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The one availability base (S76): every "is it shown, is it selectable" decision reads one
 * condition, in the one format (events-and-forms.md, "Conditions"), from a fact source, through the
 * one evaluator. A form's fields, branches, options and pages go through `Forms::availability()` and
 * `Forms::validate()` (`FormWalk`); everything another module owns (price-ladder tiers, offers, the
 * adjudicated aid, assignment-set options) goes through `available()` here. The same call serves the form
 * (over draft answers), the submit and the fee components and materializer (over registration facts);
 * only the fact source differs.
 *
 * Pure: no database, hook, current user, request or clock, so it runs inside a transaction too.
 *
 * Decision N1 (#156): the facade and its three methods.
 */
final class Availability
{
    public function __construct(
        private readonly ConditionParser $parser = new ConditionParser(),
        private readonly ConditionEvaluator $evaluator = new ConditionEvaluator(),
    ) {
    }

    /**
     * Whether a stored condition holds; no condition always holds.
     *
     * @param array<array-key, mixed>|null $condition
     * @throws SystemFailure an invalid condition or a key the facts do not declare
     */
    public function holds(?array $condition, Facts $facts): bool
    {
        return $condition === null
            || $this->evaluator->holds($this->parser->parse($condition, new ContextScope($facts)), $facts);
    }

    /**
     * The things whose condition holds, in the order given, keyed by their availability key.
     *
     * @template T of Conditional
     * @param list<T> $candidates
     * @return array<string, T>
     * @throws SystemFailure an invalid condition, a key the facts do not declare or a key given twice
     */
    public function available(array $candidates, Facts $facts): array
    {
        $available = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $candidate->availabilityKey();
            if (isset($seen[$key])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('duplicate_availability_key ' . $key);
            }
            $seen[$key] = true;
            if ($this->holds($candidate->availabilityCondition(), $facts)) {
                $available[$key] = $candidate;
            }
        }

        return $available;
    }

    /**
     * Checks a condition when it is saved, against the keys and types its fact source will declare.
     *
     * @param array<array-key, mixed> $condition
     * @param array<string, ValueType> $declared
     * @throws SystemFailure an invalid condition or a key that is not declared
     */
    public function check(array $condition, array $declared): void
    {
        $this->parser->parse($condition, new DeclaredScope($declared));
    }
}
