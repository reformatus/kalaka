<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Ledger\Api\ChargeLine;

/**
 * The label of an itemised payment line (SB-13). A ledger line stores no label: it is named from its source
 * when read (a charge from its fee component, a feature line from its feature, an adjustment from a fixed
 * text). None of those names exists yet (the fee components come with S19), so this names no line and the
 * payment table shows its totals only.
 */
class LineLabels
{
    /** The line's label, or null when it has none. */
    public function labelOf(ChargeLine $line): ?string
    {
        return null;
    }
}
