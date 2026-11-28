<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A registration as a payment provider stores it on a request line: `12:47K3`, the event ID and the reference
 * code. Composed and parsed only here (registrations-and-identity.md, "Registrations").
 */
final class RegistrationRef
{
    private function __construct()
    {
    }

    public static function compose(RegistrationKey $key): string
    {
        return $key->eventId . ':' . $key->code;
    }

    /** The key, or null when the text is not a composed reference or its code is no reference code. */
    public static function parse(string $ref): ?RegistrationKey
    {
        if (preg_match('/\A([1-9][0-9]{0,9}):([0-9A-Z]{3,20})\z/', $ref, $parts) !== 1) {
            return null;
        }
        try {
            return new RegistrationKey((int) $parts[1], $parts[2]);
        } catch (\Kalaka\Foundation\Api\Error\InvalidInput) {
            return null;
        }
    }
}
