<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A key a code declaration asked for is held by a participant field the declarer may not take: a `staff` or `system`
 * field, a field of another type, or another declarer's field (`ParticipantFields::checkDeclarable()`, the
 * declaration of an extension field). Its own code; the text is the rule violation's until one is approved.
 * [UNAPPROVED N300]
 */
final class FieldKeyHeld extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_field_key_held';
    }
}
