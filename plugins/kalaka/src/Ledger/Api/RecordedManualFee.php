<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/** What `Ledger::recordManualFee()` returns: the entry, and whether this call wrote it. */
final readonly class RecordedManualFee
{
    public function __construct(public ManualFee $fee, public bool $inserted)
    {
    }
}
