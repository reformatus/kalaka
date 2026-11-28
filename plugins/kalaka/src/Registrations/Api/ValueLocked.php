<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An additional form's write to a field its participant field locks after the first write
 * (`ParticipantFields::lockedAfterFirstWrite()`): a different value, or a clear. Its own code;
 * the text is the rule violation's until one is approved.
 */
final class ValueLocked extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_value_locked';
    }
}
