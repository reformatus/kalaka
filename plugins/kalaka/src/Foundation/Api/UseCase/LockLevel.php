<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

/** The levels of the lock order, lowest first: foundation.md, "Lock order". */
enum LockLevel: int
{
    case Event = 1;
    case Quota = 2;
    case Registration = 3;
    case Other = 4;
}
