<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The act that last made a registration `registered` (registrations-and-identity.md, "Status, capacity and
 * waitlist"): its own submit, a staff admission from the waitlist, or a staff restore. Stored beside
 * `registered_at` and written only with it; `registration.from_waitlist` reads `Admission`. [UNAPPROVED N518]
 */
enum RegisteredBy: string
{
    case Submit = 'submit';
    case Admission = 'admission';
    case Restore = 'restore';
}
