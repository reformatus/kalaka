<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * What aid needs checked before it is proposed or saved, for the preview and the save alike: its two field keys
 * must not be held by a form, staff or system field of the event, or by an extension's field of another type.
 */
final class AidInput
{
    /** The longest label the organizers give the aid. */
    public const MAX_LABEL = 191;

    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when another field holds `aid_can_pay` or `aid_award` */
    public function assertKeysFree(int $eventId): void
    {
        $wanted = [
            AidRequests::CAN_PAY_FIELD => SetAid::NUMBER_TYPE,
            AidComponent::AWARD_FIELD => SetAid::NUMBER_TYPE,
        ];
        foreach ($this->fields->of($eventId, true) as $field) {
            $type = $wanted[$field->key] ?? null;
            if ($type !== null && ($field->source !== ParticipantField::SOURCE_EXTENSION || $field->type !== $type)) {
                throw new InvalidInput();
            }
        }
    }
}
