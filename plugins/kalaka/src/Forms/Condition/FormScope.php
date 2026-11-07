<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * In a form, a condition reads answers only, and only those of the fields that come before its
 * chain in document order (events-and-forms.md, "Values and conditions"): never a later field, a
 * field of its own branch or anything that is not a field.
 */
final readonly class FormScope implements ConditionScope
{
    /** @param list<FormField> $fields the fields before the chain */
    public function __construct(private array $fields, private Contributions $contributions)
    {
    }

    public function typeOf(string $key): ValueType
    {
        $type = $this->contributions->fieldTypes()[$this->field($key)->blockName] ?? null;
        if ($type === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_field_block ' . $this->field($key)->blockName);
        }

        return $type->valueType();
    }

    public function choices(string $key): ?array
    {
        $field = $this->field($key);

        return $field->blockName === ChoiceFieldType::BLOCK_NAME
            ? ChoiceFieldType::optionValues($field->attributes)
            : null;
    }

    /** @throws SystemFailure problem `condition_key_not_earlier` */
    private function field(string $key): FormField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
        throw new SystemFailure('condition_key_not_earlier ' . $key);
    }
}
