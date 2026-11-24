<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * What the share needs checked before it is proposed or saved, for the preview and the save alike: its two field
 * keys are judged by core's `ParticipantFields::checkDeclarable()` as declarer `support` (the one rule for
 * every extension: a form's field of the type is adopted; a staff or system field, another type or another
 * declarer's field refuses). [UNAPPROVED N154]
 */
final class ShareInput
{
    public function __construct(private readonly ParticipantFields $fields = new ParticipantFields())
    {
    }

    /** @throws RuleViolation when another field holds `share_mode` or `share_amount` (not declarable by the share) */
    public function checkKeysDeclarable(int $eventId): void
    {
        $this->fields->checkDeclarable(
            $eventId,
            ShareComponent::MODE_FIELD,
            SetShare::CHOICE_TYPE,
            SetShare::DECLARER
        );
        $this->fields->checkDeclarable(
            $eventId,
            ShareComponent::AMOUNT_FIELD,
            SetShare::NUMBER_TYPE,
            SetShare::DECLARER
        );
    }
}
