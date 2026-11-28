<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Ledger\Api\ManualFeeType;

/**
 * Records one manual charge („Díj”, money.md, "Recording payments by hand"): a fact the `manual` fee component
 * composes into its own `charge` line, with a title the registrant sees. Ticked as completed, it also records
 * the payment of the same amount. Carried by the per-event action `kalaka_record_money`. Returns the entry's
 * `id` and, when completed, the payment line's `line`.
 */
final class RecordCharge extends RecordManualFee
{
    public static function name(): string
    {
        return 'kalaka/record-charge';
    }

    protected function type(): ManualFeeType
    {
        return ManualFeeType::Charge;
    }
}
