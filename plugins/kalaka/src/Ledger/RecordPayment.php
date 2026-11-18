<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Ledger\Api\LineKind;

/**
 * Records one manual payment (money.md, "Recording payments by hand"): a negative `manual_payment` line with
 * the method and the value date, and the optional bank reference or payer note as its note. Carried by the
 * per-event action `kalaka_record_payment` inside its grant's view, or full access. Returns the line's `id`.
 */
final class RecordPayment extends RecordManualEntry
{
    public static function name(): string
    {
        return 'kalaka/record-payment';
    }

    public function action(): string
    {
        return 'kalaka_record_payment';
    }

    protected function kind(): LineKind
    {
        return LineKind::ManualPayment;
    }
}
