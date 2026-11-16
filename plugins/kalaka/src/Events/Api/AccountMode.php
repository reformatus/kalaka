<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/**
 * The event's account mode (registrations-and-identity.md, "Accounts on the registration form"; owner
 * ruling 2026-11-14): one per event, for every registration form of the event. In `Mandatory` and
 * `Optional` mode someone not logged in who opens a registration form is sent to WordPress's own
 * registration page first (in `Optional` mode it links back to the form without an account); `Off` is the
 * behaviour before accounts: no sign-up and no owner.
 */
enum AccountMode: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case Off = 'off';
}
