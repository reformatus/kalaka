<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** What `Seats::retake()` did for one pool: a seat was free, it went over the limit, or there was nothing to take. */
enum SeatOutcome: string
{
    case Held = 'held';
    case Over = 'over';
    case None = 'none';
}
