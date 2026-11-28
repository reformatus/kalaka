<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Portal\Api\OwedKind;
use Kalaka\Pricing\Api\LineName;

/**
 * The words of the owed rows of the money table. A ledger line stores no label: it is named from its source when
 * read (a charge from its fee component, a feature line from its feature).
 */
class LineLabels
{
    /**
     * The word of an owed row that names nothing (an unnamed fee line, an erased manual charge's title). Its own
     * participant-side constant, so an admin relabel of `LineKind::Charge` does not move it.
     */
    public const FEE = 'Díj';

    /**
     * The words of an owed row of the money table: the base price, a manual line's title (the fact's, never
     * the line's), a component's own word, and „Díj” for a line that names nothing. A manual discount whose title
     * was erased reads „Kedvezmény”.
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
