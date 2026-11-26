<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The claim token of a guest draft (S74): what a link or the quiz handed over is kept on the server under
 * it, and the visitor's browser holds it in the cookie `kalaka_draft` until the draft is claimed, submitted
 * or swept after the registration's close. 128 random bits as 22 characters of unpadded base64url; only its
 * SHA-256 is stored. A secret: never in a log line, a URL or the audit.
 *
 * The cookie follows the registrant cookie policy (`RegistrantCookie`), like the active registration's: `Lax`
 * is what lets it travel on the 303 that answers the quiz's cross-site POST and back through WordPress's login
 * and sign-up.
 */
final class DraftClaim
{
    public const COOKIE = 'kalaka_draft';

    public const PATTERN = '^[A-Za-z0-9_-]{22}$';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function isValid(string $token): bool
    {
        return preg_match('/' . self::PATTERN . '/D', $token) === 1;
    }

    /** What is stored of a token. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The token of the current request's cookie, or null when there is none or it is malformed. */
    public static function current(): ?string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against the pattern
        $token = isset($_COOKIE[self::COOKIE]) ? wp_unslash($_COOKIE[self::COOKIE]) : null;

        return is_string($token) && self::isValid($token) ? $token : null;
    }

    /**
     * Sets the cookie until the instant (the event's registration closing), or clears it with null. Nothing once
     * the headers are sent.
     */
    public static function setCookie(?string $token, ?\DateTimeImmutable $expires = null): void
    {
        if ($token === null) {
            unset($_COOKIE[self::COOKIE]);
        } else {
            $_COOKIE[self::COOKIE] = $token;
        }
        RegistrantCookie::set(
            self::COOKIE,
            $token ?? '',
            $token === null || $expires === null ? -1 : $expires->getTimestamp(),
        );
    }
}
