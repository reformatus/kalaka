<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * The confirmation threshold (money.md, "Confirmation"; modules-and-extensions.md, "The extension surface"): an
 * inside point that says how much of a payer's target must be paid for confirmation. Core's default is the full
 * target; the deposit extension lowers it to the deposit. A threshold above the target, or below zero, is held
 * to the target or zero. It reads its own current settings and writes nothing.
 */
interface ConfirmationThreshold
{
    /** @throws \Throwable anything, which fails the confirmation rule */
    public function threshold(ConfirmationThresholdInput $input): Money;
}
