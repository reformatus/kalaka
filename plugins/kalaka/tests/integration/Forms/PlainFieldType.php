<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;

/** A test type that overrides nothing of the value API. */
final class PlainFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'acme/test-plain-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        return FieldAnswer::stored('x');
    }
}
