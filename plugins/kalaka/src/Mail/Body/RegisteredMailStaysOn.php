<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The "registered" mail of an event with accounts is switched off (`AccountLinkRule`): a refusal of
 * `UpdateActionMail`, with its own code and the text it always had.
 */
final class RegisteredMailStaysOn extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(AccountLinkRule::NO_SWITCH_OFF);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_registered_stays_on';
    }
}
