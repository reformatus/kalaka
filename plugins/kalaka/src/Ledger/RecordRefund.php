<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;

/**
 * Records one manual refund (money.md, "Recording payments by hand"): a positive `manual_refund` line, money
 * returned by any recorded payment method. A refund is never a correction: that is `RecordCorrection`.
 * Carried by the per-event action `kalaka_record_refund`. Returns the line's `id`.
 */
final class RecordRefund extends RecordManualEntry
{
    public static function name(): string
    {
        return 'kalaka/record-refund';
    }

    public function action(): string
    {
        return Ledger::RECORD_REFUND;
    }

    protected function kind(array $input): LineKind
    {
        return LineKind::ManualRefund;
    }
}
