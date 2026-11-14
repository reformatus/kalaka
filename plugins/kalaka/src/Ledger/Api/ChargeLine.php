<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;

/**
 * A line of a payer that is not a payment fact (a charge, an adjustment, a feature line), as stored: the
 * signed amount and its source. A line carries no label; a reader that shows it names it from its source.
 */
final readonly class ChargeLine
{
    public function __construct(
        public int $id,
        public LineKind $kind,
        public Money $amount,
        public Subject $source,
    ) {
    }
}
