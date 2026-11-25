<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An account mode that the event's "registered" mail cannot back: the mail is off or has no template, or its
 * template lacks the set-password link. `reason` is the mail rule's text, for the operator's console
 * (`wp kalaka event update`); it is not sent to REST.
 */
final class AccountMailNotReady extends RuleViolation
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'kalaka_account_mail_not_ready';
    }
}
