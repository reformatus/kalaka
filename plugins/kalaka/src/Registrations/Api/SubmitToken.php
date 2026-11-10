<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The token a form page carries into its submit, so a repeated submit (a double click, a resent
 * request) returns the first one's registration instead of creating another. 128 random bits as 22
 * characters of unpadded base64url, the shape of a secret link. Nothing is stored when it is issued;
 * it is a secret all the same: never written into a log line.
 */
final class SubmitToken
{
    public const PATTERN = '^[A-Za-z0-9_-]{22}$';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function isValid(string $token): bool
    {
        return preg_match('/' . self::PATTERN . '/D', $token) === 1;
    }
}
