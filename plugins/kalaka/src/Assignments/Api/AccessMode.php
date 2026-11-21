<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

/**
 * How participants reach a set's options (modules-and-extensions.md, "Assignment sets"). Only request
 * mode is built (S44); direct mode is S65's, and the none mode comes with the sets that need it.
 */
enum AccessMode: string
{
    /** The participant writes a request (the request field); staff confirm assignments later. */
    case Request = 'request';
}
