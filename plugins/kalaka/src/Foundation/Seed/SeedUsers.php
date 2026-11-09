<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * One WordPress user per default role, created with WordPress functions outside any use case. Nobody
 * gets a Kaláka capability. A login that exists is kept unchanged.
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

    /**
     * @param \Closure(string): void $report
     * @throws CommandFailure when WordPress refuses a user
     */
    public function run(\Closure $report): void
    {
        foreach (self::USERS as $login => $displayName) {
            if (get_user_by('login', $login) !== false) {
                $report('Kept user ' . $login . ' (exists).');
                continue;
            }

            $created = wp_insert_user([
                'user_login' => $login,
                'user_pass' => 'password',
                'user_email' => $login . '@example.org',
                'display_name' => $displayName,
                'role' => $login,
            ]);
            if (is_wp_error($created)) {
                $error = new CommandFailure('User ' . $login . ': ' . $created->get_error_message());
                throw $error;
            }
            $report('Created user ' . $login . '.');
        }
    }
}
