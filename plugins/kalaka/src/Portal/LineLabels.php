<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Ledger\Api\ChargeLine;
use Kalaka\Portal\Api\OwedKind;
use Kalaka\Pricing\Api\LineName;

/**
 * The label of an itemised payment line (SB-13). A ledger line stores no label: it is named from its source
 * when read (a charge from its fee component, a feature line from its feature). `labelOf()` reads none of
 * those names yet (UX prototypes the registrant's page first), so it names no line and the payment table shows
 * its totals only; `owedLabelOf()` names the owed rows of the money table.
 */
class LineLabels
{
    /**
     * The word of an owed row that names nothing (an unnamed fee line, an erased manual charge's title). Its own
     * participant-side constant, so an admin relabel of `LineKind::Charge` does not move it. [UNAPPROVED T3]
     */
    public const FEE = 'Díj';

    /** The line's label, or null when it has none. */
    public function labelOf(ChargeLine $line): ?string
    {
        return null;
    }

    /**
     * The words of an owed row of the money table: the base price, a manual line's title (the fact's, never
     * the line's), a component's own word, and „Díj” for a line that names nothing. A manual discount whose title
     * was erased reads „Kedvezmény”. [UNAPPROVED T1]
     */
    public function owedLabelOf(LineName $name): string
    {
        $title = trim((string) $name->title);

        return match (OwedKind::of($name)) {
            OwedKind::Base => 'Részvételi díj',
            OwedKind::ManualCharge => $title === '' ? self::FEE : $title,
            OwedKind::ManualDiscount => $title === '' ? 'Kedvezmény' : $title,
            OwedKind::Component => $name->label,
            OwedKind::Fee => self::FEE,
        };
    }
}
