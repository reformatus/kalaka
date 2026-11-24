<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * What the registrant can do now, decided on the server (the page and a script read it, none decides it):
 * `payCard` is the one payable rule (`PayableNow`) and a payment provider that accepts the event's currency.
 */
final readonly class RegistrationCan
{
    public function __construct(public bool $payCard = false)
    {
    }
}
