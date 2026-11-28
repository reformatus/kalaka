<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Decision\DecisionRule;

/**
 * The registered rules (foundation.md, "Facts, decisions and computed values"), declared core first; the
 * evaluation asks them in that order.
 */
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
     * @throws \InvalidArgumentException when the kind is taken or malformed, a state is malformed or missing, the
     *                                   kind or a state has no label, or the base state is not a declared state;
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

        foreach ($rule->marks() as $state => $mark) {
            if (!isset($states[$state])) {
                throw new \InvalidArgumentException('A mark belongs to a declared state.');
            }
            if (trim($mark['icon']) === '') {
                throw new \InvalidArgumentException('A mark has an icon.');
            }
        }

        $default = $rule->defaultState();
        if ($default !== null && !isset($states[$default->state])) {
            throw new \InvalidArgumentException('A base state is a declared state.');
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

    /** Whether the kind is registered as a core rule's. */
    public function isCore(string $kind): bool
    {
        foreach ($this->core as $rule) {
            if ($rule->kind() === $kind) {
                return true;
            }
        }

        return false;
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
