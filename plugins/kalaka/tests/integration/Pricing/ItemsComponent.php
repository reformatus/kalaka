<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/** A test component that owes per item: one line for each item of `$items` (item → amount), at the extras stage. */
final class ItemsComponent implements FeeComponent
{
    /** @param array<string, string> $items item key → amount the participant owes for it */
    public function __construct(public array $items)
    {
    }

    public function id(): string
    {
        return 'things';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $items = [];
        foreach ($this->items as $item => $amount) {
            $items[$item] = [Payer::PARTICIPANT => Money::of($amount, $input->currency)];
        }

        return FeeTargets::ofItems($items);
    }
}
