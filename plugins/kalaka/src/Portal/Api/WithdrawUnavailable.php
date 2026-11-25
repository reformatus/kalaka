<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The participant's own withdrawal cannot be done: there is no active registration, the code sent is not the active
 * registration's, or its status cannot withdraw (already withdrawn or removed). Its own code; the text is the rule
 * violation's generic one until the owner approves one. A status that changes under the lock, while the act runs, is
 * Registrations' `StatusActRefused`. [UNAPPROVED N664]
 */
final class WithdrawUnavailable extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_withdraw_unavailable';
    }
}
