<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Support\Offers\OfferChangeGuard;
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

    /**
     * @throws \Kalaka\Foundation\Api\Error\RuleViolation when the save would refuse because a registration has
     *     selected an offer it changes: the apply asks for every save before it runs one, so it never half-saves
     */
    public function save(): SettingsSave
    {
        (new OfferChangeGuard())->checkAgainstStored((int) $this->input['event_id'], $this->offers);

        return new SettingsSave(new SetSupportOffers(), $this->input);
    }
}
