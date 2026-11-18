<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The one cookie policy of a registrant's browser (SB-5): `HttpOnly; SameSite=Lax; Path=/`, `Secure` on https,
 * with no `Domain`. Portal's active registration (`kalaka_active`, and its bad-link flag) and the guest draft's
 * claim token (`DraftClaim`) both set their cookies here. `Lax` is what lets a cookie travel on a top-level
 * redirect from another site (the 303 that answers the quiz's POST) and back through WordPress's login.
 * [UNAPPROVED N14] (#194 review B3: shared instead of repeated.)
 */
final class RegistrantCookie
{
    /**
     * Sets the cookie: a session cookie when `$expires` is 0, deleted when it is negative, else until `$expires`.
     * Nothing once the headers are sent.
     */
    public static function set(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires < 0 ? 1 : $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
