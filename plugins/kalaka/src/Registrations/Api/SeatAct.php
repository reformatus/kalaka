<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** The registration's own act that took a seat, stored on the seat. */
enum SeatAct: string
{
    case Submit = 'submit';
    case Admission = 'admission';
    case Restore = 'restore';
    case Placement = 'placement';
}
