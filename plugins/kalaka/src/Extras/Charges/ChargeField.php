<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Checks the field an extra charge names, for the preview and the save alike: an active participant field
 * of the event that is a consent or a number field, and a number field with decimals 0 (the quantity is
 * whole). It reads the event's participant fields (no post).
 */
final class ChargeField
{
    /** A consent's block name; it stores `1` or `0`. */
    private const CONSENT = 'kalaka/consent-field';

    /** A number field's block name (Forms). */
    private const NUMBER = 'kalaka/number-field';

    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when the event has no active consent or whole-number field with that key */
    public function check(int $eventId, string $fieldKey): void
    {
        foreach ($this->fields->of($eventId) as $field) {
            /** @var ParticipantField $field */
            if ($field->key !== $fieldKey) {
                continue;
            }
            if ($field->type === self::CONSENT) {
                return;
            }
            if ($field->type === self::NUMBER && (int) ($field->settings['decimals'] ?? 0) === 0) {
                return;
            }
            break;
        }

        throw new InvalidInput();
    }
}
