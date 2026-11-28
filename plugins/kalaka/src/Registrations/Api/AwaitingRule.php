<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * When a request source's request awaits an answer, stated as data (`RequestSource::awaitingRule()`): any-of groups
 * of all-of field tests. The rule holds when every test of at least one group holds. Core compiles it to SQL for the
 * admin grid's "awaits an answer" column (`Requests::awaitingCondition()`) and `Requests::awaits()` evaluates it in
 * PHP with `matches()`, so both mean the same; a source writes no SQL and gets no database access. A source has no
 * rule when its event can have no request from it (`awaitingRule()` returns null); a rule has at least one group and
 * every group at least one test.
 */
final readonly class AwaitingRule
{
    /** @param list<list<FieldTest>> $groups */
    public function __construct(public array $groups)
    {
        if ($groups === []) {
            throw new \LogicException('An awaiting rule needs at least one group.');
        }
        foreach ($groups as $group) {
            if ($group === []) {
                throw new \LogicException('A group of an awaiting rule needs at least one test.');
            }
        }
    }

    /** @param array<string, string> $values the registration's current field values, key → stored value */
    public function matches(array $values): bool
    {
        foreach ($this->groups as $group) {
            foreach ($group as $test) {
                if (!$test->matches($values)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
