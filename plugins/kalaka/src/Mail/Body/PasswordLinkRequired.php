<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The "registered" mail of an event with accounts is assigned or switched on with a body that does
 * not read `account.password_link` (`AccountLinkRule`): a refusal of `UpdateActionMail`, with its
 * own code and the text it always had.
 */
final class PasswordLinkRequired extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(AccountLinkRule::NEEDS_LINK);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_needs_password_link';
    }
}
