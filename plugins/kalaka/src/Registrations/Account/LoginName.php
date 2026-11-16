<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

/**
 * The login name a sign-up on WordPress's registration page gets (S15; owner ruling 2026-11-14: the username
 * is hidden and set on the server): the e-mail's local part in lowercase, only `[a-z0-9._-]`, at most 50
 * characters, then `2`, `3` and so on until it is free. Never empty (`resztvevo` when nothing is left, even
 * for an empty or invalid e-mail), at most 60 characters, and it passes `validate_username()` and
 * `illegal_user_logins`.
 * // [UNAPPROVED N5] the login name.
 */
final class LoginName
{
    /** The longest base; a suffix of up to 9 digits keeps it within WordPress's 60. */
    private const BASE_LENGTH = 50;

    /** The base when the e-mail leaves nothing. */
    public const FALLBACK = 'resztvevo';

    public static function from(string $email): string
    {
        $at = strpos($email, '@');
        $local = strtolower($at === false ? $email : substr($email, 0, $at));
        $base = substr((string) preg_replace('/[^a-z0-9._-]/', '', $local), 0, self::BASE_LENGTH);
        if ($base === '') {
            $base = self::FALLBACK;
        }
        $login = $base;
        for ($suffix = 2; !self::free($login); $suffix++) {
            $login = $base . ($suffix <= 100 ? $suffix : wp_rand(101, 999999999));
        }

        return $login;
    }

    /** Whether the login is valid, not taken and not one WordPress refuses. */
    private static function free(string $login): bool
    {
        /** @var array<array-key, mixed> $illegal */
        $illegal = (array) apply_filters('illegal_user_logins', []);
        $illegal = array_map(static fn (mixed $name): string => strtolower((string) $name), $illegal);

        return validate_username($login)
            && username_exists($login) === false
            && !in_array($login, $illegal, true);
    }
}
