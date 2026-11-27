<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The claim token of a guest draft (S74): what a link or the quiz handed over, or the guest's own typing, is
 * kept on the server under it, and the visitor's browser holds it in the cookie `kalaka_draft` until the draft
 * is claimed, submitted or swept after the registration's close. 128 random bits as 22 characters of unpadded
 * base64url; only its SHA-256 is stored. A secret: never in a log line, a URL or the audit.
 *
 * The cookie follows the registrant cookie policy (`RegistrantCookie`), like the active registration's: `Lax`
 * is what lets it travel on the 303 that answers the quiz's cross-site POST and back through WordPress's login
 * and sign-up.
 *
 * A link's or the quiz's answers (the handoff) wait under a second token in their own cookie
 * (`HANDOFF_COOKIE`, same policy and lifetime) until the next page load settles them against the draft the
 * first cookie names (N1106). The quiz's cross-site POST carries no `Lax` cookie, so it cannot know that
 * draft, and it never touches the first cookie.
 */
final class DraftClaim
{
    public const COOKIE = 'kalaka_draft';

    /** The cookie of a handoff that waits to be settled against the draft `COOKIE` names. */
    public const HANDOFF_COOKIE = 'kalaka_handoff';

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
        return self::read(self::COOKIE);
    }

    /** The token of the handoff that waits in the current request's cookie, or null. */
    public static function handoff(): ?string
    {
        return self::read(self::HANDOFF_COOKIE);
    }

    /**
     * Sets the cookie until the instant (the event's registration closing), or clears it with null. Nothing once
     * the headers are sent.
     */
    public static function setCookie(?string $token, ?\DateTimeImmutable $expires = null): void
    {
        self::write(self::COOKIE, $token, $expires);
    }

    /** Sets the handoff's cookie as `setCookie()` sets the draft's, or clears it with null. */
    public static function setHandoffCookie(?string $token, ?\DateTimeImmutable $expires = null): void
    {
        self::write(self::HANDOFF_COOKIE, $token, $expires);
    }

    private static function read(string $name): ?string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against the pattern
        $token = isset($_COOKIE[$name]) ? wp_unslash($_COOKIE[$name]) : null;

        return is_string($token) && self::isValid($token) ? $token : null;
    }

    private static function write(string $name, ?string $token, ?\DateTimeImmutable $expires): void
    {
        if ($token === null) {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $token;
        }
        RegistrantCookie::set(
            $name,
            $token ?? '',
            $token === null || $expires === null ? -1 : $expires->getTimestamp(),
        );
    }
}
