<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What a card payment may ask for now, set only while `can.payCard` (the page offers the amounts and the endpoint
 * checks the same limits, `Payments\Api\CardAmountLimits`, so the two cannot disagree). `full` is what is payable now
 * and `max` is the same (no overpaying); `min` is the effective minimum, never above `full`. `advance` is the
 * advance the registrant may pay instead of the whole: null for now. The deposit extension fills it later with
 * money.md's deposit payment request, max(0, threshold - payments so far), capped at the remaining balance, shown
 * only while it is above zero. [UNAPPROVED N814]
 */
final readonly class CardAmountsView
{
    public function __construct(public Money $full, public ?Money $advance, public Money $min, public Money $max)
    {
    }
}
