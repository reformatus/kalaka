<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Sending is resumed by a person, never by the system or by the command line without a user (mail.md,
 * "Pausing"). Its own code; the text is the rule violation's until one is approved.
 */
final class ResumeNeedsPerson extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_mail_resume_needs_person';
    }
}
