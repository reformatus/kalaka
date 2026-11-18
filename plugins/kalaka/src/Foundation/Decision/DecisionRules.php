<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

/** The registered rules, core first; foundation.md, "Facts, decisions and computed values". */
final class DecisionRules
{
    private static ?self $current = null;

    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /** @var list<DecisionRule> */
    private array $core = [];

    /** @var list<DecisionRule> */
    private array $extension = [];

    /** @var array<string, true> */
    private array $kinds = [];

    /**
     * @throws \InvalidArgumentException when the kind is taken or malformed, a state is malformed or missing, or
     *                                   the kind or a state has no label; nothing is registered then
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
        if (trim($rule->label()) === '') {
            throw new \InvalidArgumentException('A decision kind has a label.');
        }
        $states = $rule->states();
        if ($states === []) {
            throw new \InvalidArgumentException('A decision rule declares at least one state.');
        }
        foreach ($states as $state => $label) {
            if (preg_match(self::IDENTIFIER, (string) $state) !== 1 || strlen((string) $state) > 20) {
                throw new \InvalidArgumentException('A state is a lowercase identifier of at most 20 characters.');
            }
            if (trim($label) === '') {
                throw new \InvalidArgumentException('A state has a label.');
            }
        }

        $this->kinds[$kind] = true;
        if ($core) {
            $this->core[] = $rule;
        } else {
            $this->extension[] = $rule;
        }
    }

    /** The registry the evaluation uses; the readers of the declarations (`Decisions`) ask it. */
    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('Foundation has not booted: no decision rules yet.');
    }

    /** @internal Foundation's boot publishes the one registry the evaluation uses. */
    public static function publish(self $rules): void
    {
        self::$current = $rules;
    }

    /** @return list<DecisionRule> every rule in reading order: core first, then extensions, each as added */
    public function all(): array
    {
        return [...$this->core, ...$this->extension];
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
