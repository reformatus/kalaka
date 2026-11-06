<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/** The base of field types: new methods of `FieldType` get their default here. */
abstract class AbstractFieldType implements FieldType
{
    abstract public function blockName(): string;

    abstract public function answer(FieldTypeInput $input): FieldAnswer;

    /** Text, unless the type says otherwise. */
    public function valueType(): ValueType
    {
        return ValueType::Text;
    }

    /** The stored string, as both the data and the text, unless the type says otherwise. */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';

        return Value::text($stored, $stored);
    }

    /**
     * The text without the whitespace at either end.
     *
     * @throws InvalidInput when the text is not valid UTF-8 (nothing a browser sends)
     */
    protected static function trimmed(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidInput();
        }

        return preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text) ?? $text;
    }
}
