<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The secret link of a registration and the token of the active registration
 * (registrations-and-identity.md, "Access"). Only Registrations reads the stored secret: another module
 * presents a link or a token and gets the registration's key back when it matches.
 *
 * - The link carries the key beside the secret: `kalaka_link=<eventId>.<code>.<secret>` (SB-10). The
 *   registration is loaded by its key and the secret compared with `hash_equals()`, never in SQL.
 * - The active-registration token is `v1.<eventId>.<code>.<issuedAt>.<mac>` (SB-5): `issuedAt` in Unix
 *   seconds, `mac` the unpadded base64url HMAC-SHA256 of `v1|eventId|code|issuedAt|` and the current
 *   secret, under a key derived from `wp_salt('auth')`. The secret never leaves the server, a new secret
 *   revokes every token issued before it, and a token is good for `MAX_AGE` seconds from its issue (SB-7).
 */
final class SecretLinks
{
    /** The query parameter of a secret link, on any page. */
    public const PARAMETER = 'kalaka_link';

    /** SB-7: a token is good for 24 hours from its issue; it does not slide. */
    public const MAX_AGE = 86400;

    /** How far a token's issue time may be ahead of the clock (clock skew between servers). */
    private const FUTURE_LEEWAY = 60;

    private const TOKEN = '/\Av1\.([1-9][0-9]{0,18})\.([0-9A-Z]{4,16})\.([0-9]{1,12})\.([A-Za-z0-9_-]{43})\z/D';

    private const LINK = '/\A([1-9][0-9]{0,18})\.([0-9A-Z]{4,16})\.([A-Za-z0-9_-]{22})\z/D';

    public function __construct(private readonly RegistrationRepository $repository = new RegistrationRepository())
    {
    }

    /** Whether the presented secret is the registration's current one. */
    public function matches(RegistrationKey $key, string $presented): bool
    {
        $registration = $this->repository->find($key);

        return $registration !== null && hash_equals($registration->secret, $presented);
    }

    /**
     * The registration's secret link on the page given (any URL of the site), or null when there is no such
     * registration. The mails carry the same link (S33).
     */
    public function url(RegistrationKey $key, string $page): ?string
    {
        $registration = $this->repository->find($key);
        if ($registration === null) {
            return null;
        }

        return add_query_arg(
            self::PARAMETER,
            $key->eventId . '.' . $key->code . '.' . $registration->secret,
            $page
        );
    }

    /** The key of the registration whose current link this parameter value is; null for anything else. */
    public function fromLink(string $value): ?RegistrationKey
    {
        if (preg_match(self::LINK, $value, $parts) !== 1) {
            return null;
        }
        $key = self::key($parts[1], $parts[2]);

        return $key !== null && $this->matches($key, $parts[3]) ? $key : null;
    }

    /** The active-registration token issued at the given time; null when there is no such registration. */
    public function activeToken(RegistrationKey $key, \DateTimeImmutable $issuedAt): ?string
    {
        $registration = $this->repository->find($key);
        if ($registration === null) {
            return null;
        }
        $issued = $issuedAt->getTimestamp();

        return 'v1.' . $key->eventId . '.' . $key->code . '.' . $issued . '.' . self::mac($registration, $issued);
    }

    /**
     * The key the token names, when its MAC matches the registration's current secret and it was issued
     * less than `MAX_AGE` seconds before `$now`; null for anything else (a bad shape, an unknown
     * registration, a regenerated secret, an expired or a future token).
     */
    public function verifyActiveToken(string $token, \DateTimeImmutable $now): ?RegistrationKey
    {
        if (preg_match(self::TOKEN, $token, $parts) !== 1) {
            return null;
        }
        $issued = (int) $parts[3];
        $age = $now->getTimestamp() - $issued;
        if ($age >= self::MAX_AGE || $age < -self::FUTURE_LEEWAY) {
            return null;
        }
        $key = self::key($parts[1], $parts[2]);
        $registration = $key === null ? null : $this->repository->find($key);
        if ($registration === null || !hash_equals(self::mac($registration, $issued), $parts[4])) {
            return null;
        }

        return $key;
    }

    /** The key, when the code is a valid reference code in canonical form. */
    private static function key(string $eventId, string $code): ?RegistrationKey
    {
        try {
            return new RegistrationKey((int) $eventId, $code);
        } catch (InvalidInput) {
            return null;
        }
    }

    private static function mac(Registration $registration, int $issued): string
    {
        $key = $registration->key;
        $message = 'v1|' . $key->eventId . '|' . $key->code . '|' . $issued . '|' . $registration->secret;
        $secret = hash_hmac('sha256', 'kalaka active registration', wp_salt('auth'), true);
        $mac = hash_hmac('sha256', $message, $secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
