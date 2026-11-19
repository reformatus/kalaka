<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A grant that repeats another grant's user, event, view and bound value: a user's actions for a view belong
 * in one grant. It keeps the rule violation's text and status and has a code of its own.
 */
final class DuplicateGrant extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_duplicate_grant';
    }
}
