<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An additional form's submit to a registration that is not active: a waitlisted registration has no additional
 * forms (owner ruling 2026-11-25); it can only see that it is waitlisted. Its own code; the text is the rule
 * violation's until one is approved.
 */
final class RegistrationNotActive extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_registration_not_active';
    }
}
