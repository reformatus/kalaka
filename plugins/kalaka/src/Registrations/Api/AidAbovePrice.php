<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An aid award above the full price the participant has to pay: the answer is refused and nothing is written
 * (money.md, "Sponsor and church support"). Its own code, `kalaka_aid_over_price`, so the answer dialog can tell it
 * from the other refusals. [UNAPPROVED N1132] [UNAPPROVED T1131]
 */
final class AidAbovePrice extends RuleViolation
{
    protected function defaultMessage(): string
    {
        return 'A megadott támogatás több, mint a fizetendő összeg.'; // [UNAPPROVED T1131]
    }

    public function errorCode(): string
    {
        return 'kalaka_aid_over_price';
    }
}
