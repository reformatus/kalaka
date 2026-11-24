<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A status act the registration's status does not allow (admitting someone who is not waitlisted, restoring someone
 * who is not withdrawn or removed, withdrawing someone who already left another way). Its own code and text.
 * [UNAPPROVED T500]
 */
final class StatusActRefused extends RuleViolation
{
    protected function defaultMessage(): string
    {
        return 'A jelentkezés jelenlegi állapotában ez a művelet nem hajtható végre.';
    }

    public function errorCode(): string
    {
        return 'kalaka_status_act_refused';
    }
}
