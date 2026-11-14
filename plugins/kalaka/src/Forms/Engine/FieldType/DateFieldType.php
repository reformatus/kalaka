<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\DateText;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A calendar day as `YYYY-MM-DD`; no range (an age rule is not the field type's). */
final class DateFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'kalaka/date-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $date = self::trimmed($input->value);
        if (
            preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return FieldAnswer::error('Adj meg egy érvényes dátumot.');
        }

        return FieldAnswer::stored($date);
    }

    public function valueType(): ValueType
    {
        return ValueType::Date;
    }

    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';

        return Value::date($stored, DateText::of($stored));
    }
}
