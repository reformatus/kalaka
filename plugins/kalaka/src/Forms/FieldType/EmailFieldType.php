<?php

declare(strict_types=1);

namespace Kalaka\Forms\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** An email address: trimmed, valid for WordPress's `is_email()`, otherwise kept as typed. */
final class EmailFieldType extends AbstractFieldType
{
    public function blockName(): string
    {
        return 'kalaka/email-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $email = self::trimmed($input->value);
        if (is_email($email) === false) {
            return FieldAnswer::error('Adj meg egy érvényes e-mail-címet.');
        }

        return FieldAnswer::stored($email);
    }
}
