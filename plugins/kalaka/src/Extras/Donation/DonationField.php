<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Checks the field a donation setting names, for the preview and the save alike: an active participant
 * field of the event that is a core number field taking whole numbers (decimals 0). It reads the event's
 * participant fields (no post). [UNAPPROVED N5, N14]
 */
final class DonationField
{
    /** The number field's block name (Forms). */
    private const NUMBER = 'kalaka/number-field';

    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when the event has no active whole-number field with that key */
    public function check(int $eventId, string $fieldKey): void
    {
        foreach ($this->fields->of($eventId) as $field) {
            /** @var ParticipantField $field */
            if (
                $field->key === $fieldKey
                && $field->type === self::NUMBER
                && (int) ($field->settings['decimals'] ?? 0) === 0
            ) {
                return;
            }
        }

        throw new InvalidInput();
    }
}
