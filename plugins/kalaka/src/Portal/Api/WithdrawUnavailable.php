<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The participant's own withdrawal cannot be done because the active registration's status cannot withdraw
 * (already withdrawn or removed). Its own code; the text is the rule violation's generic one until the owner
 * approves one. No active registration, or another registration's code, is `WithdrawStale`; a status that changes
 * under the lock, while the act runs, is Registrations' `StatusActRefused`. [UNAPPROVED N664]
 */
final class WithdrawUnavailable extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_withdraw_unavailable';
    }
}
