<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

/** Who wrote an assignment fact. */
enum AssignmentSource: string
{
    /** Placed by hand by staff (`kalaka/place-registration`). */
    case Staff = 'staff';

    /** Proposed by an assignment proposer (S77). */
    case Proposer = 'proposer';
}
