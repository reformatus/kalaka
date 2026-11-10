<?php

declare(strict_types=1);

namespace Kalaka\Forms\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * A phone number, stored in international form: spaces, hyphens and parentheses removed, then `+` and
 * 8 to 15 digits. A Hungarian domestic number, `06` and 8 or 9 digits, is stored as `+36` and those digits.
 */
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
        if (preg_match('/\A06([0-9]{8,9})\z/', $phone, $domestic) === 1) {
            $phone = '+36' . $domestic[1];
        }
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $phone) !== 1) {
            return FieldAnswer::error('Adj meg egy érvényes telefonszámot, például 06301234567 vagy +36301234567.');
        }

        return FieldAnswer::stored($phone);
    }
}
