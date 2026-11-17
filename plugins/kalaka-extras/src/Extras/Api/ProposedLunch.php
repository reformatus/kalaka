<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Extras\Lunch\LunchComponent;
use Kalaka\Extras\Lunch\SetLunch;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** Proposed lunch items for an event, for a money settings preview and apply (`Lunch::propose()`). */
final readonly class ProposedLunch implements ProposedComponentSettings
{
    /**
     * @param list<LunchItem> $items the items as read from the input
     * @param array<string, mixed> $input `kalaka/set-lunch`'s input
     */
    public function __construct(public array $items, private array $input)
    {
    }

    public function componentId(): string
    {
        return LunchComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new LunchComponent(proposed: $this->items);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetLunch(), $this->input);
    }
}
