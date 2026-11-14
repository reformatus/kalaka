<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Query\Query;

/**
 * Who holds full access, read from WordPress's `usermeta` with plain statements, so the full-access use
 * cases may read it inside their run without firing a hook or filling a cache. The one holder rule: a user
 * holds it by their own capability or through a role that carries it (as `get_users(['capability' => …])`
 * counts them), although Kaláka itself gives it only per user. The last-holder check (use case, profile, CLI,
 * abilities) and the activation grant both ask `holders()`.
 */
final class FullAccessQuery extends Query
{
    /**
     * The users who hold full access by the holder rule, ascending: one statement on `usermeta`'s
     * `meta_key` index.
     *
     * @return list<int>
     */
    public function holders(): array
    {
        $patterns = array_map(self::pattern(...), [AdminAccess::FULL_ACCESS, ...self::carryingRoles()]);
        $matches = implode(' OR ', array_fill(0, count($patterns), 'm.meta_value LIKE %s'));

        return $this->scope(fn (): array => array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->select(
                'holder',
                'SELECT m.user_id FROM %i m JOIN %i u ON u.ID = m.user_id'
                . ' WHERE m.meta_key = %s AND (' . $matches . ') ORDER BY m.user_id',
                [
                    $this->usersTable('usermeta'),
                    $this->usersTable('users'),
                    $this->capabilitiesMetaKey(),
                    ...$patterns,
                ]
            )
        ));
    }

    /** Whether any user holds full access by the holder rule. */
    public function anyHolder(): bool
    {
        return $this->holders() !== [];
    }

    /** Whether the user holds the capability as their own (what the use cases write): one statement. */
    public function holds(int $userId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'holder',
            'SELECT 1 AS found FROM %i WHERE user_id = %d AND meta_key = %s AND meta_value LIKE %s LIMIT 1',
            [
                $this->usersTable('usermeta'),
                $userId,
                $this->capabilitiesMetaKey(),
                self::pattern(AdminAccess::FULL_ACCESS),
            ]
        ) !== []);
    }

    /** Whether the WordPress user exists: one statement. */
    public function userExists(int $userId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'user',
            'SELECT ID FROM %i WHERE ID = %d',
            [$this->usersTable('users'), $userId]
        ) !== []);
    }

    /**
     * The roles that carry the capability, from WordPress's roles already in memory.
     *
     * @return list<string>
     */
    private static function carryingRoles(): array
    {
        $roles = [];
        foreach (wp_roles()->roles as $name => $role) {
            if (!empty($role['capabilities'][AdminAccess::FULL_ACCESS])) {
                $roles[] = (string) $name;
            }
        }

        return $roles;
    }

    /** A serialized `"<key>";b:1;` entry of the capabilities meta (a capability or a role), LIKE's wildcards escaped. */
    private static function pattern(string $key): string
    {
        $entry = serialize($key) . 'b:1;';

        return '%' . addcslashes($entry, '_%\\') . '%';
    }
}
