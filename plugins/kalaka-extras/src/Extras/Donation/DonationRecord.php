<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

/** A row of `kalaka_extras_donations`: the participant field whose amount an event charges as a donation. */
final class DonationRecord
{
    public function __construct(
        public readonly int $eventId,
        public string $fieldKey,
        public int $version = 0,
    ) {
    }
}
