<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * "The last holder cannot lose it" (foundation.md, "Views and grants") covers WordPress's own ways of
 * deleting a user, which do not run Kaláka's use cases: the users screen, the REST users endpoint and
 * `wp user delete`. The first two check `delete_user`/`remove_user`, refused here through `map_meta_cap`;
 * WP-CLI checks no capability, so the `delete_user` action stops it. Plain code that calls
 * `wp_delete_user()` past the check is logged and the deletion proceeds. Not guarded: `wp user
 * remove-cap`, `wp user delete --network` (which fires `wpmu_delete_user`, not `delete_user`) and a
 * direct `remove_cap()`.
 */
final class LastHolderGuard
{
    private const DELETING = ['delete_user', 'delete_users', 'remove_user'];

    /**
     * `map_meta_cap`: refuses deleting the only holder, from the users screen and REST alike. The bulk
     * `delete_users` check names no user and is left alone; the per-user `delete_user` check still runs.
     * The check reads `usermeta` on every call: a users-screen page of twenty users runs twenty or more
     * queries, acceptable at this site's scale. A per-request memo is not safe, because the bulk-delete
     * loop needs a fresh read after each deletion. A failed read is reported and refuses the deletion.
     *
     * @param list<string> $caps
     * @param array<int, mixed> $args
     * @return list<string>
     */
    public static function metaCaps(array $caps, string $cap, int $userId, array $args): array
    {
        if (!in_array($cap, self::DELETING, true)) {
            return $caps;
        }
        $target = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 0;
        if ($target > 0) {
            try {
                $onlyHolder = self::isOnlyHolder($target);
            } catch (\Throwable $error) {
                Boundary::report('checking the last full-access holder', $error);
                $onlyHolder = true;
            }
            if ($onlyHolder) {
                $caps[] = 'do_not_allow';
            }
        }

        return $caps;
    }

    /**
     * `delete_user`: WordPress fires it immediately before it deletes the user, on every path. Under
     * WP-CLI, whose `wp user delete` skips the capability check, stop with the command's own error; a
     * plain `wp_delete_user()` call is logged (one error-log line) and the deletion proceeds.
     *
     * `$commandLine` is WordPress's own `WP_CLI` at run time; the tests pass it to exercise both paths
     * without defining the constant for the whole suite.
     */
    public static function deleting(int $userId, ?bool $commandLine = null): void
    {
        try {
            $onlyHolder = self::isOnlyHolder($userId);
        } catch (\Throwable $error) {
            // Fail closed, as metaCaps() does: an unreadable holder list is treated as the last holder.
            Boundary::report('checking the last full-access holder', $error);
            $onlyHolder = true;
        }
        if (!$onlyHolder) {
            return;
        }
        $commandLine ??= defined('WP_CLI') && WP_CLI;
        if ($commandLine) {
            \WP_CLI::error(
                'Cannot delete the last user with full access to Kaláka. Give full access to another user first.'
            );
        }
        Boundary::report(
            'guarding the last user with full access from deletion',
            new \RuntimeException(
                'The last user with full access was deleted past the capability check; nobody holds full access now.'
            )
        );
    }

    private static function isOnlyHolder(int $userId): bool
    {
        $query = new FullAccessQuery();

        return $query->holds($userId) && $query->holders() === [$userId];
    }
}
