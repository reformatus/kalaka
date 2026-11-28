<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * What aid needs checked before it is proposed or saved, for the preview and the save alike: the label, and the
 * two field keys, which core's `ParticipantFields::checkDeclarable()` judges as declarer `aid` (the one rule
 * for every extension: a form's number field is adopted; a staff or system field, another type or another
 * declarer's field refuses).
 */
final class AidInput
{
    /** The longest label the organizers give the aid. */
    public const MAX_LABEL = 191;

    /** The label the aid may not have, lower case: the manual adjustment's approved word. */
    private const RESERVED_LABEL = 'kedvezmény';

    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws InvalidInput when the label is „Kedvezmény” (any case, trimmed): the manual adjustment's word */
    public function assertLabelAllowed(string $label): void
    {
        if (mb_strtolower(trim($label), 'UTF-8') === self::RESERVED_LABEL) {
            throw new InvalidInput();
        }
    }

    /** @throws RuleViolation when a field of the event holds `aid_can_pay` or `aid_award` that aid may not declare */
    public function checkKeysDeclarable(int $eventId): void
    {
        foreach ([AidRequests::CAN_PAY_FIELD, AidComponent::AWARD_FIELD] as $key) {
            $this->fields->checkDeclarable($eventId, $key, SetAid::NUMBER_TYPE, SetAid::DECLARER);
        }
    }
}
