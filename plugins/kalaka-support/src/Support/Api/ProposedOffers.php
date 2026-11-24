<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Support\Offers\SetSupportOffers;
use Kalaka\Support\Offers\SupportComponent;

/** Proposed support offers for an event, for a money settings preview and apply (`Offers::propose()`). */
final readonly class ProposedOffers implements ProposedComponentSettings
{
    /**
     * @param list<Offer> $offers the offers as read from the input
     * @param array<string, mixed> $input `kalaka/set-support-offers`'s input
     */
    public function __construct(public array $offers, private array $input)
    {
    }

    public function componentId(): string
    {
        return SupportComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new SupportComponent(proposed: $this->offers);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetSupportOffers(), $this->input);
    }
}
