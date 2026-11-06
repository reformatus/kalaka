<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

/** The registered time rules, in the order they were added; foundation.md, "Deferred work". */
final class TimeRules
{
    /** @var list<TimeRule> */
    private array $rules = [];

    public function add(TimeRule $rule): void
    {
        $this->rules[] = $rule;
    }

    /** @return list<TimeRule> */
    public function all(): array
    {
        return $this->rules;
    }
}
