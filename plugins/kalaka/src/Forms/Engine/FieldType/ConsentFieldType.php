<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A consent: sent as a boolean, stored as `1`. A consent not given is not answered. */
final class ConsentFieldType extends AbstractFieldType
{
    public const BLOCK_NAME = 'kalaka/consent-field';

    /** The message of a required consent not given; core uses it where other fields get the required message. */
    public const NOT_GIVEN = 'A továbblépéshez ezt el kell fogadnod.';

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_bool($input->value)) {
            throw new InvalidInput();
        }

        return $input->value ? FieldAnswer::stored('1') : FieldAnswer::notAnswered();
    }
}
