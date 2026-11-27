<?php

declare(strict_types=1);

namespace Kalaka\Aid\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Turning an event's aid off is refused while an open registration has a request awaiting an answer or holds an
 * award above zero (owner 2026-11-27, merge-queue Q17 b). Its own code, `kalaka_aid_in_use`. [UNAPPROVED N120]
 * [UNAPPROVED T1132]
 */
final class AidInUse extends RuleViolation
{
    protected function defaultMessage(): string
    {
        // [UNAPPROVED T1132]
        return 'A támogatás nem kapcsolható ki, amíg van megválaszolatlan vagy megítélt támogatási kérelem.';
    }

    public function errorCode(): string
    {
        return 'kalaka_aid_in_use';
    }
}
