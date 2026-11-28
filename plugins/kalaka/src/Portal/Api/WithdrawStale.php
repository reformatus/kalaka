<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The page the withdrawal came from is out of date: there is no active registration, or the `code` sent is not the
 * active registration's (a stale tab). Its own code; the text is the rule violation's generic one until the owner
 * approves one. A status that cannot withdraw is `WithdrawUnavailable`. [UNAPPROVED N664]
 */
final class WithdrawStale extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_withdraw_stale';
    }
}
