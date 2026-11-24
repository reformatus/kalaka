<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** A consent: sent as a boolean, stored as `1` when ticked and `0` when shown and left unticked. */
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

        return $input->value ? FieldAnswer::stored('1') : FieldAnswer::stored('0');
    }

    /**
     * The data stay the stored string (a condition compares `1` and `0`); the text, which the grid's detail and the
     * mails show, is the words the grid already uses for a consent: „Igen” for a given one, „Nem” for a stored `0`.
     */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';

        return Value::text($stored, $stored === '1' ? 'Igen' : ($stored === '0' ? 'Nem' : ''));
    }

    /** A given consent is a ticked box; null for none and for a stored `0`, which reopens unticked. */
    public function prefill(FieldTypeInput $input): mixed
    {
        return $input->value === '1' ? true : null;
    }
}
