<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The server's check of answers against a form's definition: only declared fields are
 * accepted, a blank answer is no answer, and `required` is applied here, not by the types.
 */
final class AnswerValidator
{
    private const REQUIRED = 'Ezt a mezőt ki kell töltened.';

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
        foreach ($definition->fields as $field) {
            $value = $answers[$field->key] ?? null;
            if (!array_key_exists($field->key, $answers) || $this->isBlank($value)) {
                if ($field->isRequired()) {
                    $errors[$field->key] = $field->blockName === ConsentFieldType::BLOCK_NAME
                        ? ConsentFieldType::NOT_GIVEN
                        : self::REQUIRED;
                }
                continue;
            }

            $type = $contributions->fieldTypes()[$field->blockName] ?? null;
            if ($type === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('unknown_field_block ' . $field->blockName);
            }
            $answer = $type->answer(new FieldTypeInput($value, $field->attributes));
            if ($answer->error !== null) {
                $errors[$field->key] = $answer->error;
            } elseif ($answer->value !== null) {
                $submitted[$field->key] = $value;
                $stored[$field->key] = $answer->value;
            } elseif ($field->isRequired()) {
                $errors[$field->key] = self::REQUIRED;
            }
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the messages are fixed texts, no input
            throw new InvalidAnswers($errors);
        }

        return new ValidatedAnswers($submitted, $stored);
    }

    /** `""`, whitespace only, `[]` and `false` are no answer. */
    private function isBlank(mixed $value): bool
    {
        return $value === false
            || $value === []
            || (is_string($value) && preg_match('/\A[\s\p{Z}]*\z/u', $value) === 1);
    }
}
