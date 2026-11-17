<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Extras\Donation\DonationField;
use Kalaka\Extras\Donation\DonationRepository;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;

/**
 * The donation for every other module and the settings screen (S72): the amount field an event charges as
 * a donation, and a proposed one to hand to `Pricing\Api\MoneySettings` (preview → confirm → apply;
 * money.md, "Money settings apply"). Plain reads, outside any use case.
 */
final class Donation
{
    public function __construct(
        private readonly DonationRepository $donations = new DonationRepository(),
        private readonly Events $events = new Events(),
        private readonly DonationField $field = new DonationField(),
    ) {
    }

    /** The key of the amount field the event charges as a donation; null when it has none. */
    public function field(int $eventId): ?string
    {
        return $this->donations->find($eventId)?->fieldKey;
    }

    /**
     * A proposed donation setting: the key of an active amount field of the event, or null for no donation.
     *
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the event has no active amount field with that key
     */
    public function propose(int $eventId, ?string $fieldKey): ProposedDonation
    {
        $this->events->find($eventId) ?? throw new NotFound();
        if ($fieldKey !== null) {
            $this->field->check($eventId, $fieldKey);
        }

        return new ProposedDonation($eventId, $fieldKey);
    }
}
