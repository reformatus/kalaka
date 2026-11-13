<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\MoneyRelevantFieldType;

/** A test type that says its value affects money, in a namespace of its own. */
final class SurchargeFieldType extends AbstractFieldType implements MoneyRelevantFieldType
{
    public function blockName(): string
    {
        return 'acme/test-surcharge-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        return FieldAnswer::stored('x');
    }
}
