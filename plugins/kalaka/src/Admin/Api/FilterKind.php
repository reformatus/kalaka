<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

/**
 * What a column's ad hoc filter compares (S37): the grid tells the view query, from the column's own type, never
 * the client. Each kind takes only its own operators (`ColumnFilter`).
 */
enum FilterKind: string
{
    /**
     * A status, a choice or a decision's state: one of the listed stored values (`isAny`, `isNone`). The list may
     * also hold `ColumnFilter::NO_ANSWER`, which stands for „Nincs válasz”: no value (a field) or no record
     * (a decision).
     */
    case Members = 'members';
    /** Text, or the code: the folded text contains the value (`contains`). */
    case Text = 'text';
    /** A number-valued field, or the sorszám: `is`, `lessThan`, `greaterThan`, `between`. */
    case Number = 'number';
    /** A date-valued field: `before`, `after`. */
    case Date = 'date';
    /**
     * A yes/no field: stored `1` is yes, stored `0` is no, no value is neither; the value may be
     * `ColumnFilter::NO_ANSWER` (`is`, `isNot`).
     */
    case YesNo = 'yesno';
}
