<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * What the registrant can do now, decided on the server (the page and a script read it, none decides it):
 * `withdraw` is the participant's own withdrawal (`Withdrawal`): the status is `registered` or `waitlisted` (the
 * staff withdrawal's own table, `StatusAct::Withdraw`), the site is open (`LoadState`) and the event's registration
 * has not closed (N661; `withdrawUnavailable` says `registration_closed` when it has). Card payment's `payCard`
 * joins this class in the card payment slice.
 */
final readonly class RegistrationCan
{
    /** Why `withdraw` is false although the status allows it. */
    public const WITHDRAW_REGISTRATION_CLOSED = 'registration_closed';

    /**
     * @param ?string $withdrawUnavailable `registration_closed` when the status and the site would allow the
     *     withdrawal but the event's registration has closed (from then on only staff can withdraw); null when
     *     it is available, and for the other cases (a status that cannot withdraw, a closed site), which the page
     *     already knows from the status and the availability.
     */
    public function __construct(public bool $withdraw = false, public ?string $withdrawUnavailable = null)
    {
    }
}
