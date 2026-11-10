<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

/** The registered time rules, in the order they were added; foundation.md, "Deferred work". */
final class TimeRules
{
    /** @var list<TimeRule> */
    private array $rules = [];

    /** @throws \LogicException when a rule with this name is already registered */
    public function add(TimeRule $rule): void
    {
        foreach ($this->rules as $existing) {
            if ($existing->name() === $rule->name()) {
                throw new \LogicException('A time rule named ' . esc_html($rule->name()) . ' is already registered.');
            }
        }
        $this->rules[] = $rule;
    }

    /** @return list<TimeRule> */
    public function all(): array
    {
        return $this->rules;
    }
}
