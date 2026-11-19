<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;

/**
 * Records one correction (the owner's ruling 2026-11-19): a payment row of either direction, flagged as a
 * correction, with a required reason, no method and no value date; it counts from the time it is recorded.
 * `direction` `in` is a `manual_payment` (money in, below zero), `out` a `manual_refund` (money out, above
 * zero); `amount` is positive. As a payment fact it counts toward confirmation and the balances like any
 * recorded payment. Carried by the per-event action `kalaka_record_payment` (money-write). Returns the
 * line's `id`.
 */
final class RecordCorrection extends RecordManualEntry
{
    public static function name(): string
    {
        return 'kalaka/record-correction';
    }

    public function action(): string
    {
        return Ledger::RECORD_PAYMENT;
    }

    protected function isCorrection(): bool
    {
        return true;
    }

    protected function kind(array $input): LineKind
    {
        return ($input['direction'] ?? null) === 'out' ? LineKind::ManualRefund : LineKind::ManualPayment;
    }

    protected function extraProperties(): array
    {
        return [
            'direction' => ['type' => 'string', 'enum' => ['in', 'out']],
            'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
        ];
    }

    protected function extraRequired(): array
    {
        return ['direction', 'reason'];
    }
}
