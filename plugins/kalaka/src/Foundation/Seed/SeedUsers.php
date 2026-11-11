<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * One WordPress user per default role, and one administrator with Kaláka's full access, created with
 * WordPress functions outside any use case. A login that exists is kept unchanged.
 */
final class SeedUsers
{
    private const USERS = [
        'administrator' => 'Teszt Adminisztrátor',
        'editor' => 'Teszt Szerkesztő',
        'author' => 'Teszt Szerző',
        'contributor' => 'Teszt Közreműködő',
        'subscriber' => 'Teszt Feliratkozó',
    ];

    /** The login of the administrator who also has `kalaka_full_access`, e.g. to build forms. */
    public const FULL_ACCESS_LOGIN = 'kalaka_admin';

    /** [UNAPPROVED N13] */
    private const FULL_ACCESS_NAME = 'Teszt Kaláka-adminisztrátor';

    /**
     * @param \Closure(string): void $report
     * @throws CommandFailure when WordPress refuses a user
     */
    public function run(\Closure $report): void
    {
        foreach (self::USERS as $login => $displayName) {
            $this->user($login, $displayName, $login, $report);
        }
        $created = $this->user(self::FULL_ACCESS_LOGIN, self::FULL_ACCESS_NAME, 'administrator', $report);
        if ($created !== null) {
            $created->add_cap(AdminAccess::FULL_ACCESS);
        }
    }

    /**
     * @param \Closure(string): void $report
     * @return \WP_User|null the user created, or null when the login exists
     * @throws CommandFailure when WordPress refuses the user
     */
    private function user(string $login, string $displayName, string $role, \Closure $report): ?\WP_User
    {
        if (get_user_by('login', $login) !== false) {
            $report('Kept user ' . $login . ' (exists).');

            return null;
        }

        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => 'password',
            'user_email' => $login . '@example.org',
            'display_name' => $displayName,
            'role' => $role,
        ]);
        if (is_wp_error($created)) {
            $error = new CommandFailure('User ' . $login . ': ' . $created->get_error_message());
            throw $error;
        }
        $report('Created user ' . $login . '.');
        $user = get_userdata($created);

        return $user instanceof \WP_User ? $user : null;
    }
}
