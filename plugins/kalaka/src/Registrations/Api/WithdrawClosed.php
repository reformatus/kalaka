<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The participant's own withdrawal after the event's registration has closed: from then on only staff can withdraw
 * a registration. Its own code, so the page can tell it from `kalaka_withdraw_unavailable`; the text is T992.
 * [UNAPPROVED N661]
 */
final class WithdrawClosed extends RuleViolation
{
    protected function defaultMessage(): string
    {
        /** [UNAPPROVED T992] */
        return 'A jelentkezés időközben lezárult, ezért a lemondás nem sikerült.';
    }

    public function errorCode(): string
    {
        return 'kalaka_withdraw_closed';
    }
}
