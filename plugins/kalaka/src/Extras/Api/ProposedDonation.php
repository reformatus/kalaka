<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Extras\Donation\SetDonation;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** A proposed donation setting for an event, for a money settings preview and apply (`Donation::propose()`). */
final readonly class ProposedDonation implements ProposedComponentSettings
{
    /** @param string|null $fieldKey the whole-number field whose value is charged; null: no donation */
    public function __construct(public int $eventId, public ?string $fieldKey)
    {
    }

    public function componentId(): string
    {
        return DonationComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new DonationComponent(proposing: true, proposedField: $this->fieldKey);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetDonation(), ['event_id' => $this->eventId, 'field_key' => $this->fieldKey]);
    }
}
