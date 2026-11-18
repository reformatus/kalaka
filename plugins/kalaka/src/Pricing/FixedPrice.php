<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/**
 * Core's fee component: the event's current fixed price, owed by the participant (money.md, "Core money
 * scope"). Without a ladder it is the tier, so it composes at the tier stage. A preview builds it with the
 * proposed price instead of the stored one.
 */
final class FixedPrice implements FeeComponent
{
    public const ID = 'fixed_price';

    /** @param ?Money $proposed the price to compose with instead of the stored one (a preview) */
    public function __construct(
        private readonly FixedPriceRepository $prices = new FixedPriceRepository(),
        private readonly ?Money $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Tier;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $price = $this->proposed ?? $this->prices->find($input->registration->eventId)?->amount;
        if ($price === null || !$price->isPositive()) {
            return FeeTargets::none();
        }

        return new FeeTargets([Payer::PARTICIPANT => $price]);
    }
}
