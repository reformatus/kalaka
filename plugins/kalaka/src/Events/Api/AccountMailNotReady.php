<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An account mode that the event's "registered" mail cannot back: the mail is off or has no template, or its
 * template lacks the set-password link. The message is that mail rule's own approved text
 * (`AccountLinkRule`), so the refusal reads the same on every path.
 */
final class AccountMailNotReady extends RuleViolation
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public function errorCode(): string
    {
        return 'kalaka_account_mail_not_ready';
    }
}
