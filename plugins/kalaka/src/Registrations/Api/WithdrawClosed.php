<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The participant's own withdrawal after the event's registration has closed: from then on only staff can withdraw
 * a registration. Its own code, so the page can tell it from `kalaka_withdraw_unavailable`; the text is a stand-in
 * until UX's wording is approved. [UNAPPROVED N661, T-UX]
 */
final class WithdrawClosed extends RuleViolation
{
    protected function defaultMessage(): string
    {
        // [UNAPPROVED T-UX] stand-in text
        return 'A jelentkezés lezárult, ezért már csak a szervezők tudják lemondani a jelentkezést.';
    }

    public function errorCode(): string
    {
        return 'kalaka_withdraw_closed';
    }
}
