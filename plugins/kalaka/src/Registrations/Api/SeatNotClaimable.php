<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An additional form's submit that would make a new seat claim in a pool the registration does not hold (D7).
 * [UNAPPROVED N304] Its own code; the text is the rule violation's until one is approved.
 */
final class SeatNotClaimable extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_seat_not_claimable';
    }
}
