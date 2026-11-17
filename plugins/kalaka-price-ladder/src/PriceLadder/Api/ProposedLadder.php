<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder\Api;

use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\PriceLadder\SetPriceLadder;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** A proposed price ladder for an event, for a money settings preview and apply (`PriceLadder::propose()`). */
final readonly class ProposedLadder implements ProposedComponentSettings
{
    /**
     * @param list<Tier> $tiers the tiers as read from the input
     * @param array<string, mixed> $input `kalaka/set-price-ladder`'s input
     */
    public function __construct(public array $tiers, private array $input)
    {
    }

    public function componentId(): string
    {
        return PriceLadderComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new PriceLadderComponent(proposed: $this->tiers);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetPriceLadder(), $this->input);
    }
}
