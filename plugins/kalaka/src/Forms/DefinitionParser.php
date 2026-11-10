<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormChain;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Condition\Chain;
use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\Condition\Chains;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\FormScope;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Reads the field blocks of a form's block markup, in document order, whatever core blocks and
 * condition branches they sit in; each field carries the branches it sits in, and a choice field
 * the availability conditions of its options. The chains are numbered in document order, and every
 * key a condition reads is collected. The chain numbers are read from `Condition\ChainMarks`, the marks
 * the branch blocks render with. Any problem makes the form unusable (`SystemFailure`, naming
 * the problem and the field key, never a label or an option).
 */
final class DefinitionParser
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    public function __construct(
        private readonly SlotRules $slotRules = new SlotRules(),
        private readonly ConditionParser $conditions = new ConditionParser(),
    ) {
    }

    /** @var list<FormChain> the chains met so far, in order of their numbers */
    private array $chains = [];

    /** @var list<string> the keys the conditions met so far read */
    private array $read = [];

    /** @throws SystemFailure */
    public function parse(Form $form, int $version, string $content, Contributions $contributions): FormDefinition
    {
        $this->chains = [];
        $this->read = [];
        $fields = [];
        try {
            $this->collect(ChainMarks::mark(array_values(parse_blocks($content))), $contributions, $fields, []);
            $this->slotRules->check($form->id, $form->placement, $fields);
            $triggers = [];
            foreach ($fields as $field) {
                if (in_array($field->key, $this->read, true)) {
                    $triggers[] = $field->key;
                }
            }

            return new FormDefinition($form->id, $version, $fields, $this->chains, $triggers);
        } finally {
            $this->chains = [];
            $this->read = [];
        }
    }

    /**
     * @param list<array<string, mixed>|string> $sequence the siblings, with the markup between them
     * @param list<FormField> $fields
     * @param list<FormBranch> $branches the branches around the siblings, outermost first
     * @throws SystemFailure
     */
    private function collect(array $sequence, Contributions $contributions, array &$fields, array $branches): void
    {
        foreach (Chains::split($sequence) as $item) {
            if ($item instanceof Chain) {
                $this->chain($item, $contributions, $fields, $branches);
            } elseif (is_array($item)) {
                $this->block($item, $contributions, $fields, $branches);
            }
        }
    }

    /**
     * A chain's conditions read the fields collected before the chain, never those inside it; the
     * fields of each branch carry the conditions of the branches before it (which must not hold) and
     * their own.
     *
     * @param list<FormField> $fields
     * @param list<FormBranch> $branches
     * @throws SystemFailure
     */
    private function chain(Chain $chain, Contributions $contributions, array &$fields, array $branches): void
    {
        $scope = new FormScope($fields, $contributions);
        $conditions = [];
        foreach ($chain->branches as $branch) {
            $attributes = is_array($branch['attrs'] ?? null) ? $branch['attrs'] : [];
            $name = is_string($branch['blockName'] ?? null) ? $branch['blockName'] : '';
            try {
                $conditions[] = $name === Chains::ELSE_BLOCK
                    ? null
                    : $this->read($this->conditions->parse($attributes['condition'] ?? null, $scope));
            } catch (SystemFailure $e) {
                // A condition with no key to name (empty, or not an object) is named by its block.
                if (str_ends_with($e->getMessage(), ' invalid_condition')) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block, never output
                    throw new SystemFailure('invalid_condition ' . $name, $e);
                }
                throw $e;
            }
        }

        // The numbers come from the marks the blocks render with: one numbering for both.
        $number = ChainMarks::of($chain->branches[0])[0] ?? null;
        if ($number === null || $number !== count($this->chains)) {
            $error = new SystemFailure(null, new \LogicException('A chain was not marked in document order.'));
            throw $error;
        }
        $own = [];
        $earlier = [];
        foreach ($conditions as $index => $condition) {
            if ((ChainMarks::of($chain->branches[$index])[1] ?? null) !== $index) {
                $error = new SystemFailure(null, new \LogicException('A branch was not marked in chain order.'));
                throw $error;
            }
            $own[] = new FormBranch($earlier, $condition, $number, $index);
            if ($condition instanceof Condition) {
                $earlier[] = $condition;
            }
        }
        $this->chains[] = new FormChain($number, $branches, $own);

        foreach ($chain->branches as $index => $branch) {
            $this->collect(Chains::sequenceOf($branch), $contributions, $fields, [...$branches, $own[$index]]);
        }
    }

    private function read(Condition $condition): Condition
    {
        $this->read = array_values(array_unique([...$this->read, ...$condition->keys()]));

        return $condition;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<FormField> $fields
     * @param list<FormBranch> $branches
     * @throws SystemFailure
     */
    private function block(array $block, Contributions $contributions, array &$fields, array $branches): void
    {
        $name = $block['blockName'] ?? null;
        if (is_string($name)) {
            if (isset($contributions->fieldTypes()[$name])) {
                $fields[] = $this->field($name, $block, $fields, $branches, $contributions);
            } elseif (
                str_starts_with($name, 'kalaka/')
                && !\WP_Block_Type_Registry::get_instance()->is_registered($name)
            ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('unknown_field_block ' . $name);
            }
        }

        $this->collect(Chains::sequenceOf($block), $contributions, $fields, $branches);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<FormField> $fields the fields before this one
     * @param list<FormBranch> $branches
     * @throws SystemFailure
     */
    private function field(
        string $name,
        array $block,
        array $fields,
        array $branches,
        Contributions $contributions
    ): FormField {
        $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        /** @var array<string, mixed> $attributes */
        $key = $attributes['key'] ?? null;
        if (!is_string($key) || $key === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('missing_key ' . $name);
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_key ' . $key);
        }
        foreach ($fields as $earlier) {
            if ($earlier->key === $key) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('duplicate_key ' . $key);
            }
        }
        if ($name === ChoiceFieldType::BLOCK_NAME && ChoiceFieldType::optionValues($attributes) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('invalid_options ' . $key);
        }

        $optionConditions = $name === ChoiceFieldType::BLOCK_NAME
            ? $this->optionConditions($attributes, new FormScope($fields, $contributions))
            : [];

        return new FormField($key, $name, $attributes, $branches, $optionConditions);
    }

    /**
     * The availability conditions of a choice field's options (S76): an option may carry a
     * `condition`, which reads the fields before the choice field and the event's keys.
     * [UNAPPROVED N4] the option attribute `condition`.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, Condition> option value → condition, for the options that have one
     * @throws SystemFailure
     */
    private function optionConditions(array $attributes, FormScope $scope): array
    {
        $conditions = [];
        $options = is_array($attributes['options'] ?? null) ? $attributes['options'] : [];
        foreach ($options as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && array_key_exists('condition', $option)) {
                $conditions[$option['value']] = $this->read($this->conditions->parse($option['condition'], $scope));
            }
        }

        return $conditions;
    }
}
