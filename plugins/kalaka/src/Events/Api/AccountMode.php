<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/**
 * The event's account mode (registrations-and-identity.md, "Accounts on the registration form"; owner
 * ruling 2026-11-14, reworked 2026-11-18): one per event, for every registration form of the event. In
 * `Mandatory` and `Optional` mode every registration form holds the account block, which opens WordPress's
 * own login and registration pages in a window (in `Mandatory` mode the send needs the account); `Off` is
 * the behaviour before accounts: no sign-up and no owner.
 *
 * Changing the mode is refused while a published registration form of the event lacks the block, and the
 * broken-forms list is refreshed after a mode change (`UpdateEvent` through `Events::update()`; S72's screen
 * will set it the same way).
 */
enum AccountMode: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
    case Off = 'off';
}
