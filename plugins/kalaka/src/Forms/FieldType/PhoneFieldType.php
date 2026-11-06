<?php

declare(strict_types=1);

namespace Kalaka\Forms\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A phone number in international form: spaces, hyphens and parentheses removed, `+` and 8 to 15 digits. */
final class PhoneFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'kalaka/phone-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $phone = preg_replace('/[\s\p{Z}()\-]+/u', '', $input->value) ?? $input->value;
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $phone) !== 1) {
            return FieldAnswer::error('Add meg a telefonszámot nemzetközi formátumban, például: +36301234567.');
        }

        return FieldAnswer::stored($phone);
    }
}
