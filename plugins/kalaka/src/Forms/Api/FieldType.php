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

    /** The type of the value the field's answer makes: what conditions compare it as. */
    public function valueType(): ValueType;

    /** The value of a stored answer: `$input->value` is the stored string. */
    public function value(FieldTypeInput $input): Value;

    /**
     * The answer a form opens with for a stored value (events-and-forms.md, "Prefill from a link"; an
     * additional form opens with the registration's current values): `$input->value` is the stored
     * string, and the answer is what the field's controls hold, of the JSON type `answer()` takes. Null
     * opens the field empty: a type that refuses prefill always returns null, and its field left blank then
     * keeps its value (events-and-forms.md, "Changing participant fields after submit").
     * [UNAPPROVED N1] (#189's N1, the field-type contract)
     */
    public function prefill(FieldTypeInput $input): mixed;
}
