<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Checks the field a donation setting names, for the preview and the save alike: an active participant field
 * of the event whose type is the amount field. It reads the event's participant fields (no post).
 */
final class DonationField
{
    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when the event has no active amount field with that key */
    public function check(int $eventId, string $fieldKey): void
    {
        foreach ($this->fields->of($eventId) as $field) {
            /** @var ParticipantField $field */
            if ($field->key === $fieldKey && $field->type === AmountFieldType::BLOCK) {
                return;
            }
        }

        throw new InvalidInput();
    }
}
