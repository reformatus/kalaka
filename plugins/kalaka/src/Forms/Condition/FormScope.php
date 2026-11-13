<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * In a form, a condition reads the answers of the fields that come before it in document order
 * (events-and-forms.md, "Values and conditions") and the event's keys (`EventValues`, S76): never a
 * later field, a field of its own branch or any other key.
 */
final readonly class FormScope implements ConditionScope
{
    /**
     * @param list<FormField> $fields the fields before the condition
     * @param array<string, ValueType> $declared the other keys a form's condition may read
     */
    public function __construct(
        private array $fields,
        private Contributions $contributions,
        private array $declared = EventValues::TYPES,
    ) {
    }

    public function typeOf(string $key): ValueType
    {
        if (isset($this->declared[$key])) {
            return $this->declared[$key];
        }
        $type = $this->contributions->fieldTypes()[$this->field($key)->blockName] ?? null;
        if ($type === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_field_block ' . $this->field($key)->blockName);
        }

        return $type->valueType();
    }

    public function choices(string $key): ?array
    {
        if (isset($this->declared[$key])) {
            return null;
        }
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
