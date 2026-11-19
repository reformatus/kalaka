<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Pricing\Api\ConfirmationThreshold;
use Kalaka\Pricing\Api\ConfirmationThresholdInput;

/** Core's confirmation threshold (money.md, "Confirmation"): the full amount the payer owes now. */
final class FullAmountThreshold implements ConfirmationThreshold
{
    public function threshold(ConfirmationThresholdInput $input): Money
    {
        return $input->target;
    }
}
