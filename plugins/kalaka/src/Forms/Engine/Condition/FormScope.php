<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\OptionsFieldType;
use Kalaka\Forms\Api\PathFieldType;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * In a form, a condition reads the answers of the fields that come before it in document order
 * (events-and-forms.md, "Values and conditions") and the host's keys (`FormHost::factTypes()`; in an
 * event form the event's, `EventValues`, S76, and an additional form's stored values): never a
 * later field, a field of its own branch or any other key. A field of the form wins over a host key of the
 * same name: the form's own answer is always read from its answers.
 */
final readonly class FormScope implements ConditionScope
{
    /**
     * @param list<FormField> $fields the fields before the condition
     * @param array<string, ValueType> $declared the other keys a form's condition may read (`FormHost::factTypes()`)
     */
    public function __construct(
        private array $fields,
        private Contributions $contributions,
        private array $declared,
    ) {
    }

    public function typeOf(string $key): ValueType
    {
        if (!$this->isEarlier($key) && isset($this->declared[$key])) {
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
        if (!$this->isEarlier($key) && isset($this->declared[$key])) {
            return null;
        }
        $field = $this->field($key);

        return ($this->contributions->fieldTypes()[$field->blockName] ?? null) instanceof OptionsFieldType
            ? ChoiceFieldType::optionValues($field->attributes)
            : null;
    }

    public function isPath(string $key): bool
    {
        if (!$this->isEarlier($key) && isset($this->declared[$key])) {
            return false;
        }

        return ($this->contributions->fieldTypes()[$this->field($key)->blockName] ?? null) instanceof PathFieldType;
    }

    private function isEarlier(string $key): bool
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return true;
            }
        }

        return false;
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
