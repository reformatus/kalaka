<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

/** The registered rules, core first; foundation.md, "Facts, decisions and computed values". */
final class DecisionRules
{
    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /** @var list<DecisionRule> */
    private array $core = [];

    /** @var list<DecisionRule> */
    private array $extension = [];

    /** @var array<string, true> */
    private array $kinds = [];

    /**
     * @throws \InvalidArgumentException when the kind is taken or malformed, or a state is malformed or missing;
     *                                   nothing is registered then
     */
    public function add(DecisionRule $rule, bool $core): void
    {
        $kind = $rule->kind();
        if (preg_match(self::IDENTIFIER, $kind) !== 1 || strlen($kind) > 50) {
            throw new \InvalidArgumentException('A decision kind is a lowercase identifier of at most 50 characters.');
        }
        if (isset($this->kinds[$kind])) {
            throw new \InvalidArgumentException('A decision kind is registered once.');
        }
        $states = $rule->states();
        if ($states === []) {
            throw new \InvalidArgumentException('A decision rule declares at least one state.');
        }
        foreach ($states as $state) {
            if (preg_match(self::IDENTIFIER, $state) !== 1 || strlen($state) > 20) {
                throw new \InvalidArgumentException('A state is a lowercase identifier of at most 20 characters.');
            }
        }

        $this->kinds[$kind] = true;
        if ($core) {
            $this->core[] = $rule;
        } else {
            $this->extension[] = $rule;
        }
    }

    /** @return list<DecisionRule> in the order they were added */
    public function core(): array
    {
        return $this->core;
    }

    /** @return list<DecisionRule> in the order they were added */
    public function extension(): array
    {
        return $this->extension;
    }
}
