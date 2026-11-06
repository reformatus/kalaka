<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** registrations-and-identity.md, "Status, capacity and waitlist": the stored status of a registration. */
enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
    case Removed = 'removed';
}
