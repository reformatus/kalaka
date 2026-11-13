<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormChain;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\PageDefinition;
use Kalaka\Forms\Condition\Chain;
use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\Condition\Chains;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\DeclaredScope;
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

    /** Whether the form's after-submit section was met. */
    private bool $afterSubmit = false;

    /** the page whose blocks are being collected; null outside pages */
    private ?int $page = null;

    /** @var list<PageDefinition> the pages met so far, in order of their numbers */
    private array $pages = [];

    /** the page block, only at the top level of a form. */
    public const PAGE_BLOCK = ChainMarks::PAGE_BLOCK;

    /** @throws SystemFailure */
    public function parse(Form $form, int $version, string $content, Contributions $contributions): FormDefinition
    {
        $this->chains = [];
        $this->read = [];
        $this->afterSubmit = false;
        $this->page = null;
        $this->pages = [];
        $fields = [];
        try {
            $this->topLevel(ChainMarks::mark(array_values(parse_blocks($content))), $contributions, $fields);
            $this->slotRules->check($form->id, $form->placement, $fields, $this->pages);
            $triggers = [];
            foreach ($fields as $field) {
                if (in_array($field->key, $this->read, true)) {
                    $triggers[] = $field->key;
                }
            }

            return new FormDefinition($form->id, $version, $fields, $this->chains, $triggers, $this->pages);
        } finally {
            $this->chains = [];
            $this->read = [];
            $this->page = null;
            $this->pages = [];
        }
    }

    /**
     * The top level of a form: either no page (the blocks as one
     * page) or every block inside a page, whitespace and the one after-submit block aside. The after-submit
     * block is not collected: no field may sit in it.
     *
     * @param list<array<string, mixed>> $blocks the marked top-level blocks
     * @param list<FormField> $fields
     * @throws SystemFailure
     */
    private function topLevel(array $blocks, Contributions $contributions, array &$fields): void
    {
        $hasPages = false;
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            $hasPages = $hasPages || $name === self::PAGE_BLOCK;
            if ($name === AfterSubmit::BLOCK) {
                $this->afterSubmit($block, $contributions);
            }
        }
        $rest = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['blockName'] ?? null) !== AfterSubmit::BLOCK
        ));
        if (!$hasPages) {
            $this->collect($rest, $contributions, $fields, []);

            return;
        }

        foreach ($rest as $block) {
            $name = $block['blockName'] ?? null;
            if ($name === null && trim((string) ($block['innerHTML'] ?? '')) === '') {
                continue;
            }
            if ($name !== self::PAGE_BLOCK) {
                $this->fail('content_outside_pages');
            }
            $this->page($block, $contributions, $fields);
        }
    }

    /**
     * A page: its condition reads the fields before it (earlier pages) and the
     * event's keys; page 0 has none; a terminal page has one and holds no field.
     *
     * @param array<string, mixed> $block
     * @param list<FormField> $fields
     * @throws SystemFailure
     */
    private function page(array $block, Contributions $contributions, array &$fields): void
    {
        $index = ChainMarks::pageOf($block);
        if ($index === null || $index !== count($this->pages)) {
            $error = new SystemFailure(null, new \LogicException('A page was not marked in document order.'));
            throw $error;
        }
        $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $data = $attributes['condition'] ?? null;
        $condition = $data === null
            ? null
            : $this->read($this->conditions->parse($data, new FormScope($fields, $contributions)));
        $terminal = ($attributes['terminal'] ?? false) === true;
        if ($index === 0 && $condition !== null) {
            $this->fail('first_page_condition');
        }
        if ($terminal && $condition === null) {
            $this->fail('terminal_page_unconditional ' . $index);
        }
        $label = is_string($attributes['label'] ?? null) ? $attributes['label'] : '';
        $this->pages[] = new PageDefinition($index, $label, $terminal, $condition);

        $before = count($fields);
        $this->page = $index;
        try {
            $this->collect(Chains::sequenceOf($block), $contributions, $fields, []);
        } finally {
            $this->page = null;
        }
        if ($terminal && count($fields) > $before) {
            $this->fail('field_on_terminal_page ' . $index);
        }
    }

    /** @throws SystemFailure */
    private function fail(string $problem): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a number, never output
        throw new SystemFailure($problem);
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
        $this->chains[] = new FormChain($number, $branches, $own, $this->page);

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
        if ($name === self::PAGE_BLOCK) {
            $this->fail('page_not_top_level');
        }
        if ($name === AfterSubmit::BLOCK) {
            $this->fail('after_submit_not_top_level');
        }
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

        return new FormField($key, $name, $attributes, $branches, $optionConditions, $this->page);
    }

    /**
     * The after-submit section (N12): at most one, at the top level (outside any branch or page, MP-13),
     * with no field block in it, its conditions reading only the after-submit context
     * (`AfterSubmit::TYPES`). Its fields and chains are not the form's: nothing in it is collected or
     * numbered.
     *
     * @param array<string, mixed> $block
     * @throws SystemFailure
     */
    private function afterSubmit(array $block, Contributions $contributions): void
    {
        if ($this->afterSubmit) {
            throw new SystemFailure('after_submit_count');
        }
        $this->afterSubmit = true;
        $this->checkAfterSubmit(Chains::sequenceOf($block), $contributions, new DeclaredScope(AfterSubmit::TYPES));
    }

    /**
     * @param list<array<string, mixed>|string> $sequence
     * @throws SystemFailure
     */
    private function checkAfterSubmit(array $sequence, Contributions $contributions, DeclaredScope $scope): void
    {
        foreach (Chains::split($sequence) as $item) {
            $blocks = $item instanceof Chain ? $item->branches : (is_array($item) ? [$item] : []);
            foreach ($blocks as $block) {
                $name = $block['blockName'] ?? null;
                if (is_string($name) && isset($contributions->fieldTypes()[$name])) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block name, never output
                    throw new SystemFailure('field_in_after_submit ' . $name);
                }
                if ($name === AfterSubmit::BLOCK) {
                    throw new SystemFailure('after_submit_count');
                }
                if ($item instanceof Chain && $name !== Chains::ELSE_BLOCK) {
                    $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                    $this->conditions->parse($attributes['condition'] ?? null, $scope);
                }
                $this->checkAfterSubmit(Chains::sequenceOf($block), $contributions, $scope);
            }
        }
    }

    /**
     * The availability conditions of a choice field's options (S76): an option may carry a
     * `condition`, which reads the fields before the choice field and the event's keys.
     * Decision N4 (#156): the option attribute `condition`.
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
