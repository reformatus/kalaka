<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The link of the attach mail (S15, [UNAPPROVED]): signing up with an email that already has an account
 * creates no user and reveals nothing; the attach mail goes to that account, and its link attaches the
 * registration to it once its holder is logged in (`kalaka-portal/attach-registration`).
 *
 * The link is `kalaka_attach=<eventId>.<code>.<userId>.<mac>` on any page: `mac` is the unpadded base64url
 * HMAC-SHA256 of `attach|eventId|code|userId|` and the registration's current secret, under a key derived
 * from `wp_salt('auth')`. It names the account it is for, so it attaches to that account only, and a new
 * secret revokes it. It never carries the secret.
 */
final class AttachLinks
{
    /** The query parameter of an attach link, on any page. */
    public const PARAMETER = 'kalaka_attach';

    private const LINK = '/\A([1-9][0-9]{0,18})\.([0-9A-Z]{4,16})\.([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{43})\z/D';

    public function __construct(private readonly RegistrationRepository $repository = new RegistrationRepository())
    {
    }

    /** The attach link on the page given (any URL of the site); null when there is no such registration. */
    public function url(RegistrationKey $key, int $userId, string $page): ?string
    {
        $registration = $this->repository->find($key);
        if ($registration === null) {
            return null;
        }

        return add_query_arg(
            self::PARAMETER,
            $key->eventId . '.' . $key->code . '.' . $userId . '.' . self::mac($registration, $userId),
            $page
        );
    }

    /**
     * What the parameter value names, by its shape only: the registration and the account; null for a value
     * of another shape. `matches()` checks it.
     *
     * @return array{RegistrationKey, int}|null
     */
    public static function parse(string $value): ?array
    {
        if (preg_match(self::LINK, $value, $parts) !== 1) {
            return null;
        }
        try {
            return [new RegistrationKey((int) $parts[1], $parts[2]), (int) $parts[3]];
        } catch (InvalidInput) {
            return null;
        }
    }

    /** Whether the parameter value is the current attach link of that registration for that account. */
    public static function matches(Registration $registration, string $value): bool
    {
        $named = self::parse($value);
        if ($named === null) {
            return false;
        }
        [$key, $userId] = $named;
        if ($key->eventId !== $registration->key->eventId || $key->code !== $registration->key->code) {
            return false;
        }
        $mac = substr($value, (int) strrpos($value, '.') + 1);

        return hash_equals(self::mac($registration, $userId), $mac);
    }

    private static function mac(Registration $registration, int $userId): string
    {
        $key = $registration->key;
        $message = 'attach|' . $key->eventId . '|' . $key->code . '|' . $userId . '|' . $registration->secret;
        $secret = hash_hmac('sha256', 'kalaka attach link', wp_salt('auth'), true);
        $mac = hash_hmac('sha256', $message, $secret, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
