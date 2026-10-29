<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

enum Side: string
{
    case Admin = 'admin';
    case Portal = 'portal';
}
