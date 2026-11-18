<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

/**
 * What a column's ad hoc filter compares (S37): the grid tells the view query, from the column's own type, never
 * the client. Each kind takes only its own operators (`ColumnFilter`).
 */
enum FilterKind: string
{
    /** A status, a choice or a decision's state: one of the listed stored values (`isAny`, `isNone`). */
    case Members = 'members';
    /** Text, or the code: the folded text contains the value (`contains`). */
    case Text = 'text';
    /** A number-valued field: `is`, `lessThan`, `greaterThan`, `between`. */
    case Number = 'number';
    /** The sorszám: a range, both ends included (`between`). */
    case Range = 'range';
    /** A date-valued field: `before`, `after`. */
    case Date = 'date';
    /** A yes/no field: stored `1` is yes, stored `0` is no, no value is neither (`is`, `isNot`). */
    case YesNo = 'yesno';
    /** The pseudo column `unanswered`: some of the listed field or decision columns has no answer (`isAny`). */
    case Unanswered = 'unanswered';
}
