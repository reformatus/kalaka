<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

/** The secret of a registration's link: registrations-and-identity.md, "Access". */
final class SecretLink
{
    /** 16 random bytes as 22 characters of unpadded base64url. */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
