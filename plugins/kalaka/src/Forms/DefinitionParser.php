<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Reads the field blocks of a form's block markup, in document order, whatever core blocks
 * they sit in. Any problem makes the form unusable (`SystemFailure`, naming the problem and
 * the field key, never a label or an option).
 */
final class DefinitionParser
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    public function __construct(private readonly SlotRules $slotRules = new SlotRules())
    {
    }

    /** @throws SystemFailure */
    public function parse(Form $form, int $version, string $content, Contributions $contributions): FormDefinition
    {
        $fields = [];
        $this->collect(parse_blocks($content), $contributions, $fields);
        $this->slotRules->check($form->id, $form->placement, $fields);

        return new FormDefinition($form->id, $version, $fields);
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param list<FormField> $fields
     * @throws SystemFailure
     */
    private function collect(array $blocks, Contributions $contributions, array &$fields): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name)) {
                if (isset($contributions->fieldTypes()[$name])) {
                    $fields[] = $this->field($name, $block, $fields);
                } elseif (
                    str_starts_with($name, 'kalaka/')
                    && !\WP_Block_Type_Registry::get_instance()->is_registered($name)
                ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                    throw new SystemFailure('unknown_field_block ' . $name);
                }
            }

            $inner = $block['innerBlocks'] ?? [];
            if (is_array($inner)) {
                /** @var array<array<string, mixed>> $inner */
                $this->collect($inner, $contributions, $fields);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<FormField> $fields the fields before this one
     * @throws SystemFailure
     */
    private function field(string $name, array $block, array $fields): FormField
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

        return new FormField($key, $name, $attributes);
    }
}
