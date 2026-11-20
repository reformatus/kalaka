<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Decision\DecisionRule;

/**
 * The registered rules (foundation.md, "Facts, decisions and computed values"): declared core first, and
 * evaluated in the decisions phase's order, where the rules that read a target come after the rules that
 * change one. [UNAPPROVED N2]
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

    /** @var array<string, true> the kinds of the rules that read a target */
    private array $readers = [];

    /**
     * @throws \InvalidArgumentException when the kind is taken or malformed, a state is malformed or missing, the
     *                                   kind or a state has no label, or the base state is not a declared state;
     *                                   nothing is registered then
     * @param bool $readsTarget whether the rule reads a payer's target (the late fee, confirmation): it is
     *                          evaluated after every rule that does not [UNAPPROVED N2]
     */
    public function add(DecisionRule $rule, bool $core, bool $readsTarget = false): void
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

        $default = $rule->defaultState();
        if ($default !== null && !isset($states[$default->state])) {
            throw new \InvalidArgumentException('A base state is a declared state.');
        }

        $this->kinds[$kind] = true;
        if ($readsTarget) {
            $this->readers[$kind] = true;
        }
        if ($core) {
            $this->core[] = $rule;
        } else {
            $this->extension[] = $rule;
        }
    }

    /** The registry the evaluation uses; the readers of the declarations (`Decisions`) ask it. [UNAPPROVED N1] */
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

    /**
     * The order of the decisions phase. First the rules that change a target or read none (core, then
     * extensions), then the rules that read a target: extensions, then core, so confirmation, which reads
     * every payer's target, comes last and sees the late fee. [UNAPPROVED N2, N3]
     *
     * @return list<DecisionRule>
     */
    public function inEvaluationOrder(): array
    {
        $first = [];
        $readers = [];
        foreach ($this->extension as $rule) {
            if (isset($this->readers[$rule->kind()])) {
                $readers[] = $rule;
            }
        }
        foreach ($this->core as $rule) {
            if (isset($this->readers[$rule->kind()])) {
                $readers[] = $rule;
            } else {
                $first[] = $rule;
            }
        }
        foreach ($this->extension as $rule) {
            if (!isset($this->readers[$rule->kind()])) {
                $first[] = $rule;
            }
        }

        return [...$first, ...$readers];
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
