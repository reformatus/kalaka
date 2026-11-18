<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The `charge` lines of one registration under one source and payer, summed: what the materializer has
 * recorded so far. Only the materializer reads it (money.md, "Materializer": rules never read charge lines).
 */
final readonly class ChargeTotal
{
    public function __construct(
        public Subject $source,
        public string $payer,
        public Money $total,
        public int $lines,
    ) {
    }
}
