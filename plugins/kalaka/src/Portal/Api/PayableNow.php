<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * The one rule of what a registered registration can be paid now (SB-4, money.md, "Payments"): its event is
 * published, no request awaits an answer, and the participant's balance is above zero after rounding up to the
 * payable unit. The transfer block, the status read's card payment and the start of a card payment all ask it;
 * the transfer's bank details and a provider's availability stay with their callers. Clock-free: a pure
 * function of what the caller read.
 */
final class PayableNow
{
    private function __construct()
    {
    }

    /** The amount payable now (from the participant's balance), rounded up to the payable unit; null when nothing is payable. */
    public static function amount(Availability $availability, bool $awaitsAnswer, Money $participantBalance): ?Money
    {
        $amount = $participantBalance->roundedUpToPayableUnit();
        if ($availability !== Availability::Open || $awaitsAnswer || !$amount->isPositive()) {
            return null;
        }

        return $amount;
    }
}
