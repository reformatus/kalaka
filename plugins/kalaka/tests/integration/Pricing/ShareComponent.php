<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/**
 * A test component at the share stage: the congregation takes over `$share` of the base after support,
 * X′ = max(0, min(share, base − support)), as a zero-sum pair (money.md, "Congregation share").
 */
final class ShareComponent implements FeeComponent
{
    public function __construct(private readonly string $share)
    {
    }

    public function id(): string
    {
        return 'share';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Share;
    }

    public function isInvoiceable(): bool
    {
        return false;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $base = $input->before->of(FeeStage::Tier, Payer::PARTICIPANT)
            ->plus($input->before->of(FeeStage::Support, Payer::PARTICIPANT));
        $share = Money::of($this->share, $input->currency);
        $taken = $share->isLessThan($base) ? $share : $base;
        if (!$taken->isPositive()) {
            return FeeTargets::none();
        }

        return new FeeTargets([Payer::PARTICIPANT => $taken->negated(), 'congregation' => $taken]);
    }
}
