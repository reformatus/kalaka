<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

/** An assignment fact's state: proposed (not yet shown to anyone outside staff) or confirmed. */
enum AssignmentState: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
}
