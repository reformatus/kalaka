<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/**
 * The event's account mode (registrations-and-identity.md, "Accounts on the registration form"; owner
 * ruling 2026-11-14): one per event, for every registration form of the event. In `Mandatory` and
 * `Optional` mode a registration form opened by someone not logged in begins with the account step (sign
 * up, log in, and in `Optional` mode skip); `Off` is the behaviour before accounts: no step and no owner.
 */
enum AccountMode: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case Off = 'off';
}
