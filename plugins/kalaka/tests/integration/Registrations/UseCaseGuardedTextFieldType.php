<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Registrations\SubmitAdditionalForm;

/**
 * A text field type that fails when `prefill()` runs from inside the additional form's use case (and so inside
 * its transaction): a field type is an outside point. It counts its calls.
 */
final class UseCaseGuardedTextFieldType extends AbstractFieldType
{
    public int $prefills = 0;

    public function blockName(): string
    {
        return 'kalaka/text-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        return FieldAnswer::stored(is_string($input->value) ? $input->value : '');
    }

    public function prefill(FieldTypeInput $input): mixed
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === SubmitAdditionalForm::class && $frame['function'] === 'execute') {
                throw new \LogicException('A field type ran inside the use case.');
            }
        }
        ++$this->prefills;

        return parent::prefill($input);
    }
}
