<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/** Which way a correction moves the paid total. */
enum MovementDirection: string
{
    case Raises = 'raises';
    case Lowers = 'lowers';
}
