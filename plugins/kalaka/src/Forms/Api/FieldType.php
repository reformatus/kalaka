<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * A kind of field, contributed through `Contributions::fieldType()`. An outside point: it runs
 * outside any transaction, returns a value and writes nothing. Core applies `required` and the
 * blank answers itself; a type is asked only about an answer that is not blank.
 */
interface FieldType
{
    /** The name of the field block, e.g. `kalaka/email-field`. */
    public function blockName(): string;

    /**
     * @throws InvalidInput when the value is not of the JSON type the field type takes
     */
    public function answer(FieldTypeInput $input): FieldAnswer;
}
