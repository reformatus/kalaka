<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * What the registrant can do now, decided on the server (the page and a script read it, none decides it):
 * `withdraw` is the participant's own withdrawal (`Withdrawal`): the status is `registered` or `waitlisted` (the
 * staff withdrawal's own table, `StatusAct::Withdraw`) and the site is open (`LoadState`). Card payment's `payCard`
 * joins this class in the card payment slice. [UNAPPROVED N661]
 */
final readonly class RegistrationCan
{
    public function __construct(public bool $withdraw = false)
    {
    }
}
