<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A set option's condition that a live form holding the set's request field could not read (a key that is
 * not before the request field there, or not the event's): the save is refused and the form stays as it
 * was (S44). Its own code; the text is the rule violation's until one is approved.
 */
final class LiveFormWouldBreak extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_live_form_would_break';
    }
}
