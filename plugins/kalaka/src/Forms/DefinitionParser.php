<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Condition\Chain;
use Kalaka\Forms\Condition\Chains;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\FormScope;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Reads the field blocks of a form's block markup, in document order, whatever core blocks and
 * condition branches they sit in; each field carries the branches it sits in. Any problem makes
 * the form unusable (`SystemFailure`, naming the problem and the field key, never a label or an
 * option).
 */
final class DefinitionParser
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    public function __construct(
        private readonly SlotRules $slotRules = new SlotRules(),
        private readonly ConditionParser $conditions = new ConditionParser(),
    ) {
    }

    /** @throws SystemFailure */
    public function parse(Form $form, int $version, string $content, Contributions $contributions): FormDefinition
    {
        $fields = [];
        $this->collect(array_values(parse_blocks($content)), $contributions, $fields, []);
        $this->slotRules->check($form->id, $form->placement, $fields);

        return new FormDefinition($form->id, $version, $fields);
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
                    : $this->conditions->parse($attributes['condition'] ?? null, $scope);
            } catch (SystemFailure $e) {
                // A condition with no key to name (empty, or not an object) is named by its block.
                if (str_ends_with($e->getMessage(), ' invalid_condition')) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block, never output
                    throw new SystemFailure('invalid_condition ' . $name, $e);
                }
                throw $e;
            }
        }

        $earlier = [];
        foreach ($chain->branches as $index => $branch) {
            $own = $conditions[$index];
            $inside = [...$branches, new FormBranch($earlier, $own)];
            $this->collect(Chains::sequenceOf($branch), $contributions, $fields, $inside);
            if ($own instanceof Condition) {
                $earlier[] = $own;
            }
        }
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
                $fields[] = $this->field($name, $block, $fields, $branches);
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
    private function field(string $name, array $block, array $fields, array $branches): FormField
    {
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

        return new FormField($key, $name, $attributes, $branches);
    }
}
