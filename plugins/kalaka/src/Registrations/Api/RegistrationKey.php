<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\ReferenceCode;

/**
 * A registration's identity: the event and the reference code, in canonical form only. No other spelling
 * (case, accents, padding, other digits) reaches a read, a lock or a write: registrations-and-identity.md,
 * "Reference codes".
 */
final readonly class RegistrationKey
{
    /** @throws InvalidInput when the code is not a valid reference code */
    public function __construct(public int $eventId, public string $code)
    {
        if (!ReferenceCode::isValid($code)) {
            $error = new InvalidInput();
            throw $error;
        }
    }
}
