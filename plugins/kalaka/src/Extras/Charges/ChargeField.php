<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Checks the participant field an extra charge's key meets, for the preview and the save alike: the charge is
 * linked by key, so a field need not exist, but a number field with the charge's key multiplies the amount
 * and so must take whole numbers (decimals 0; the multiplier is a whole quantity). It reads the event's
 * participant fields (no post). [UNAPPROVED N13]
 */
final class ChargeField
{
    /** A number field's block name (Forms). */
    private const NUMBER = 'kalaka/number-field';

    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when the event's number field with that key allows decimals */
    public function check(int $eventId, string $key): void
    {
        foreach ($this->fields->of($eventId) as $field) {
            /** @var ParticipantField $field */
            if (
                $field->key === $key
                && $field->type === self::NUMBER
                && (int) ($field->settings['decimals'] ?? 0) !== 0
            ) {
                throw new InvalidInput();
            }
        }
    }
}
