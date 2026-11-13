<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormBranch;
use Kalaka\Forms\Api\FormChain;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The one walk of a form's definition over answers (S76): the same at every re-render of a draft
 * and at submit. Fields are walked in document order. A field shows when every branch it sits in
 * holds; a field that does not show is skipped before its answer is looked at, so whatever was sent
 * for it is dropped, it is never required and never an error. A shown choice field offers only the
 * options whose condition holds, and a sent option that is not offered is dropped like a hidden
 * answer. A condition reads an earlier field's valid answer as its value, an earlier field that
 * was not answered, dropped or in error as empty, and the caller's facts (the event's keys) for
 * any other key. No chain is ever pending: an unanswered choice, required or optional, reads as
 * empty, so a chain on it shows its `else` until an option is picked, and the fields shown at that
 * moment are checked like any shown field (#156 N10, owner 2026-11-13).
 *
 * Only declared fields are accepted: an undeclared key or a value of the wrong JSON type is
 * `InvalidInput`, the same for a draft as at submit. Pure but for the field types' own checks.
 */
final class FormWalk
{
    public const REQUIRED = 'Ezt a mezőt ki kell töltened.';

    public function __construct(private readonly ConditionEvaluator $evaluator = new ConditionEvaluator())
    {
    }

    /**
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws SystemFailure a field of a type that is no longer contributed, a key the facts do not declare
     */
    public function run(
        FormDefinition $definition,
        array $answers,
        Facts $base,
        Contributions $contributions
    ): FormWalkResult {
        foreach (array_keys($answers) as $key) {
            if (!is_string($key) || $definition->field($key) === null) {
                throw new InvalidInput();
            }
        }

        $facts = new FormFacts(new ValueContext(), $base);
        $shown = [];
        $options = [];
        $submitted = [];
        $stored = [];
        $errors = [];
        foreach ($definition->fields as $field) {
            $type = $contributions->fieldTypes()[$field->blockName] ?? null;
            $isShown = $this->isShown($field->branches, $facts);
            $shown[$field->key] = $isShown;
            $value = null;
            if ($field->optionConditions !== []) {
                $options[$field->key] = $isShown ? $this->offered($field, $facts) : [];
            }
            if ($isShown) {
                $sent = $answers;
                if (isset($options[$field->key]) && array_key_exists($field->key, $answers)) {
                    $sent[$field->key] = $this->onlyOffered($answers[$field->key], $options[$field->key]);
                }
                $value = $this->check($field, $type, $sent, $submitted, $stored, $errors);
            }
            $facts = $facts->with($field->key, $value ?? Value::none($type?->valueType() ?? ValueType::Text));
        }

        $held = [];
        foreach ($definition->chains as $chain) {
            $held[$chain->number] = $this->held($chain, $facts);
        }

        return new FormWalkResult($shown, array_values($held), $options, $submitted, $stored, $errors);
    }

    /**
     * Every branch holds: none of the earlier conditions of its chain, and its own.
     *
     * @param list<FormBranch> $branches
     */
    private function isShown(array $branches, Facts $facts): bool
    {
        foreach ($branches as $branch) {
            if (!$this->holds($branch, $facts)) {
                return false;
            }
        }

        return true;
    }

    private function holds(FormBranch $branch, Facts $facts): bool
    {
        foreach ($branch->earlier as $condition) {
            if ($this->evaluator->holds($condition, $facts)) {
                return false;
            }
        }

        return $branch->own === null || $this->evaluator->holds($branch->own, $facts);
    }

    /**
     * The branch of a chain that shows: none while a branch around the chain does not hold, or when no
     * branch holds and the chain has no `else`. A chain's conditions read only fields before it and the
     * caller's facts, so the facts after the walk give the same result as the facts at the chain.
     */
    private function held(FormChain $chain, Facts $facts): ?int
    {
        if (!$this->isShown($chain->outer, $facts)) {
            return null;
        }
        foreach ($chain->branches as $branch) {
            if ($this->holds($branch, $facts)) {
                return $branch->index;
            }
        }

        return null;
    }

    /** @return list<string> the option values whose condition holds or that have none, in authored order */
    private function offered(FormField $field, Facts $facts): array
    {
        $offered = [];
        foreach (ChoiceFieldType::optionValues($field->attributes) ?? [] as $value) {
            $condition = $field->optionConditions[$value] ?? null;
            if ($condition === null || $this->evaluator->holds($condition, $facts)) {
                $offered[] = $value;
            }
        }

        return $offered;
    }

    /**
     * The sent answer without the options that are not offered: a single option not offered is no
     * answer, a list keeps the offered ones. Anything else is left for the type to refuse.
     *
     * @param list<string> $offered
     */
    private function onlyOffered(mixed $sent, array $offered): mixed
    {
        if (is_string($sent)) {
            return in_array($sent, $offered, true) ? $sent : '';
        }
        if (is_array($sent) && array_is_list($sent)) {
            return array_values(array_filter(
                $sent,
                static fn (mixed $value): bool => !is_string($value) || in_array($value, $offered, true)
            ));
        }

        return $sent;
    }

    /**
     * Checks one shown field's answer, adding to the three results.
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

    /** `""`, whitespace only, `[]` and `false` are no answer. */
    private function isBlank(mixed $value): bool
    {
        return $value === false
            || $value === []
            || (is_string($value) && preg_match('/\A[\s\p{Z}]*\z/u', $value) === 1);
    }
}
