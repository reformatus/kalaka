<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

/**
 * What `ViewQuery::aggregates()` computes for one column over the whole filtered view (S37, the grid's sum
 * row). Counts are integers; `sum` and `average` are decimal strings, null over no value.
 */
enum AggregateKind: string
{
    /** How many registrations the view holds; any listed column. */
    case Rows = 'rows';
    /** How many hold one of the given members: `status`, a `field:` (a choice, single or multiple) or a `decision:` column. */
    case Members = 'members';
    /** How many said yes: a `field:` whose stored value is `1` (a consent). */
    case Yes = 'yes';
    /** The total of a number-valued `field:`, non-empty values only. */
    case Sum = 'sum';
    /** How many have a non-empty value in a `field:`. */
    case Filled = 'filled';
    /** The average of a number-valued `field:` over its non-empty values, unrounded. */
    case Average = 'average';
}
