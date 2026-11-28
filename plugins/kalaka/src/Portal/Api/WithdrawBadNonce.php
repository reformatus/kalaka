<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The withdrawal form's nonce is missing or no longer valid (an old page, or a post from somewhere else). Its own
 * code, so the page can ask for a reload; the text is T1133. No active registration, or another registration's
 * code, is `WithdrawStale`, and a status that cannot withdraw is `WithdrawUnavailable`. [UNAPPROVED N1166, T1133]
 */
final class WithdrawBadNonce extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_withdraw_bad_nonce';
    }
}
