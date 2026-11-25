<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * What a `FieldTest` asks of one participant field's stored value. [UNAPPROVED N669]
 *
 * - `Filled`: the field has a non-empty value.
 * - `Empty`: the field has no value (no row, or an empty one).
 * - `OneOf`: the field's value is one of the test's values.
 * - `NoneOf`: the field has no value, or one that is not among the test's values.
 */
enum FieldTestKind: string
{
    case Filled = 'filled';
    case Empty = 'empty';
    case OneOf = 'one_of';
    case NoneOf = 'none_of';
}
