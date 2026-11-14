<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The sign-up of the account step ([UNAPPROVED] S15-3, the WordPress-native hybrid): before the submit's
 * transaction (foundation.md, "The use-case runner": a WordPress account is created before it, and if the
 * registration then fails the account simply exists), it creates a real WordPress user through the user
 * API, or finds that the email already has one. WordPress's "Anyone can register" stays as it is; WordPress
 * sends no mail of its own (`wp_insert_user()` sends none), and the account is activated by Kaláka's queued
 * set-password mail.
 *
 * The person learns nothing about which of the two happened (the form never reveals whether an email has
 * an account): the submit answers the same, and both ways hash one password, so they take about as long.
 *
 * The new user: role `subscriber`, set explicitly; a random password nobody knows (the set-password mail
 * sets the real one); the login name derived from the email's local part; the display name and nickname
 * the form's display name.
 */
final class AccountSignUp
{
    /** The longest login name it derives; WordPress allows 60. */
    private const LOGIN_LENGTH = 50;

    /**
     * @param string $email the form's contact email, as validated
     * @param string $name the form's display name, as validated
     * @throws SystemFailure when WordPress refuses the user for any other reason than its email
     */
    public function signUp(string $email, string $name): AccountOutcome
    {
        $holder = email_exists($email);
        if ($holder !== false) {
            // The same cost as creating the user: one password hash.
            wp_hash_password(wp_generate_password(32, true, true));

            return AccountOutcome::existing((int) $holder);
        }

        $created = wp_insert_user([
            'user_login' => self::login($email),
            'user_email' => $email,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $name,
            'nickname' => $name,
            'role' => 'subscriber',
        ]);
        if ($created instanceof \WP_Error) {
            // Another submit created the account in the meantime: the email has one now.
            $holder = $created->get_error_code() === 'existing_user_email' ? email_exists($email) : false;
            if ($holder !== false) {
                return AccountOutcome::existing((int) $holder);
            }
            $reason = new \RuntimeException('Creating the account failed: ' . $created->get_error_code());
            $error = new SystemFailure(null, $reason);
            throw $error;
        }

        return AccountOutcome::created($created);
    }

    /** A free login name from the email's local part: `teszt.anna`, then `teszt.anna2`, `teszt.anna3`, … */
    public static function login(string $email): string
    {
        $local = strtolower(explode('@', $email)[0]);
        $base = substr(sanitize_user($local, true), 0, self::LOGIN_LENGTH);
        if ($base === '') {
            $base = 'resztvevo';
        }
        $login = $base;
        for ($suffix = 2; username_exists($login) !== false; $suffix++) {
            $login = $suffix <= 100 ? $base . $suffix : $base . wp_rand(101, 999999999);
        }

        return $login;
    }
}
