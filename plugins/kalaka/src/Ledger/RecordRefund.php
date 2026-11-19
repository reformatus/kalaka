<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PaymentMethod;

/**
 * Records one manual refund (money.md, "Recording payments by hand"): a positive `manual_refund` line, money
 * returned by transfer or cash. With `correction` true the entry corrects an earlier one and the note, its
 * reason, is required; a correction is not linked to the entry it corrects. Carried by the per-event action
 * `kalaka_record_refund`. Returns the line's `id`.
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

    protected function kind(): LineKind
    {
        return LineKind::ManualRefund;
    }

    protected function methods(): array
    {
        return PaymentMethod::forRefund();
    }

    protected function isCorrection(array $input): bool
    {
        return ($input['correction'] ?? false) === true;
    }

    protected function extraProperties(): array
    {
        return ['correction' => ['type' => 'boolean', 'default' => false]];
    }
}
