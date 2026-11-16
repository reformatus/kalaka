<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/**
 * The core default phases a participant sees (registrations-and-identity.md, "Status, capacity and
 * waitlist"): waitlisted → awaiting payment → confirmed, or withdrawn / removed. Computed when read, from
 * the status and the confirmation decision; never stored. The configurable phase tracker comes later.
 */
enum Phase: string
{
    case Waitlisted = 'waitlisted';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Withdrawn = 'withdrawn';
    case Removed = 'removed';
}
