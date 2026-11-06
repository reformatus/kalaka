<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A rating from 1 to 5, sent as a string. */
final class RatingFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'kalaka/test-rating-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }

        return preg_match('/\A[1-5]\z/', $input->value) === 1
            ? FieldAnswer::stored($input->value)
            : FieldAnswer::error('Egy és öt között.');
    }
}
