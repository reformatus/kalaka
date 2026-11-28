<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The pending mail of a published event is not held but on its way, so it is not cleared (mail.md, "Pausing").
 * Its own code; the text is the rule violation's until one is approved.
 */
final class EventMailNotHeld extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_mail_not_held';
    }
}
