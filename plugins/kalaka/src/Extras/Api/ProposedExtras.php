<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Extras\Charges\SetExtraCharges;
use Kalaka\Extras\ExtrasComponent;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** Proposed extra charges for an event, for a money settings preview and apply (`Extras::propose()`). */
final readonly class ProposedExtras implements ProposedComponentSettings
{
    /**
     * @param list<Charge> $charges the charges as read from the input
     * @param array<string, mixed> $input `kalaka/set-extra-charges`'s input
     */
    public function __construct(public array $charges, private array $input)
    {
    }

    public function componentId(): string
    {
        return ExtrasComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new ExtrasComponent(proposed: $this->charges);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetExtraCharges(), $this->input);
    }
}
