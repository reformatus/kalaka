<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/** A test component at the support stage: a fixed reduction of the participant's part. */
final class SupportComponent implements FeeComponent
{
    public function __construct(private readonly string $amount)
    {
    }

    public function id(): string
    {
        return 'support';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Support;
    }

    public function isInvoiceable(): bool
    {
        return false;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        return new FeeTargets([Payer::PARTICIPANT => Money::of('-' . $this->amount, $input->currency)]);
    }
}
