<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A name: trimmed, inner whitespace collapsed to one space, at most 200 characters. No pattern. */
final class NameFieldType extends AbstractFieldType
{
    public const MAX_LENGTH = 200;

    public function blockName(): string
    {
        return 'kalaka/name-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $name = preg_replace('/[\s\p{Z}]+/u', ' ', self::trimmed($input->value)) ?? $input->value;
        if (mb_strlen($name) > self::MAX_LENGTH) {
            return FieldAnswer::error(TextFieldType::tooLong(self::MAX_LENGTH));
        }

        return FieldAnswer::stored($name);
    }
}
