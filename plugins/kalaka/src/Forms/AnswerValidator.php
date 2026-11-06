<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The server's check of answers against a form's definition: only declared fields are
 * accepted, a blank answer is no answer, and `required` is applied here, not by the types.
 *
 * Fields are checked in document order. A field in a branch that does not hold is skipped before
 * its answer is looked at: whatever was sent for it is dropped, it is never required and never an
 * error. A condition reads an earlier field's stored answer as its value, and an earlier field
 * that was not answered, dropped or in error as empty.
 */
final class AnswerValidator
{
    private const REQUIRED = 'Ezt a mezőt ki kell töltened.';

    public function __construct(private readonly ConditionEvaluator $evaluator = new ConditionEvaluator())
    {
    }

    /**
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers, all fields at once
     * @throws SystemFailure a field of a type that is no longer contributed
     */
    public function validate(FormDefinition $definition, array $answers, Contributions $contributions): ValidatedAnswers
    {
        foreach (array_keys($answers) as $key) {
            if (!is_string($key) || $definition->field($key) === null) {
                throw new InvalidInput();
            }
        }

        $submitted = [];
        $stored = [];
        $errors = [];
        $context = new ValueContext();
        foreach ($definition->fields as $field) {
            $type = $contributions->fieldTypes()[$field->blockName] ?? null;
            $value = null;
            if ($this->isVisible($field, $context)) {
                $value = $this->check($field, $type, $answers, $submitted, $stored, $errors);
            }
            $context = $context->with($field->key, $value ?? Value::none($type?->valueType() ?? ValueType::Text));
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the messages are fixed texts, no input
            throw new InvalidAnswers($errors);
        }

        return new ValidatedAnswers($submitted, $stored);
    }

    /**
     * Checks one visible field's answer, adding to the three results.
     *
     * @param array<array-key, mixed> $answers
     * @param array<string, mixed> $submitted
     * @param array<string, string> $stored
     * @param array<string, string> $errors
     * @return Value|null the field's value when it was answered and is valid
     * @throws InvalidInput
     * @throws SystemFailure
     */
    private function check(
        FormField $field,
        ?FieldType $type,
        array $answers,
        array &$submitted,
        array &$stored,
        array &$errors
    ): ?Value {
        $sent = $answers[$field->key] ?? null;
        if (!array_key_exists($field->key, $answers) || $this->isBlank($sent)) {
            if ($field->isRequired()) {
                $errors[$field->key] = $field->blockName === ConsentFieldType::BLOCK_NAME
                    ? ConsentFieldType::NOT_GIVEN
                    : self::REQUIRED;
            }

            return null;
        }

        if ($type === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_field_block ' . $field->blockName);
        }
        $input = new FieldTypeInput($sent, $field->attributes);
        $answer = $type->answer($input);
        if ($answer->error !== null) {
            $errors[$field->key] = $answer->error;
        } elseif ($answer->value !== null) {
            $submitted[$field->key] = $sent;
            $stored[$field->key] = $answer->value;

            return $type->value(new FieldTypeInput($answer->value, $field->attributes));
        } elseif ($field->isRequired()) {
            $errors[$field->key] = self::REQUIRED;
        }

        return null;
    }

    /** Every branch the field sits in holds: none of the earlier conditions of its chain, and its own. */
    private function isVisible(FormField $field, ValueContext $context): bool
    {
        foreach ($field->branches as $branch) {
            foreach ($branch->earlier as $condition) {
                if ($this->evaluator->holds($condition, $context)) {
                    return false;
                }
            }
            if ($branch->own !== null && !$this->evaluator->holds($branch->own, $context)) {
                return false;
            }
        }

        return true;
    }

    /** `""`, whitespace only, `[]` and `false` are no answer. */
    private function isBlank(mixed $value): bool
    {
        return $value === false
            || $value === []
            || (is_string($value) && preg_match('/\A[\s\p{Z}]*\z/u', $value) === 1);
    }
}
