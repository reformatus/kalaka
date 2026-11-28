<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProvidesBase;

/** A test component at the tier stage, like a ladder: the participant owes `$amount`, nothing when null. */
final class TierComponent implements ProvidesBase
{
    /** @param bool $base whether this component, like a ladder with tiers, provides the event's base price */
    public function __construct(
        public ?string $amount,
        private readonly string $id = 'tier',
        public bool $base = false,
    ) {
    }

    public function providesBase(int $eventId): bool
    {
        return $this->base;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Tier;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        return $this->amount === null
            ? FeeTargets::none()
            : new FeeTargets([Payer::PARTICIPANT => Money::of($this->amount, $input->currency)]);
    }
}
