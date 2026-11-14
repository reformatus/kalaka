<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Admin\Api\AdminAccess;

/**
 * The "Kaláka" column of the Users list: who holds full access. Shown only to a user who has full access
 * themselves. It reads WordPress's cached users, which the list has already loaded.
 */
final class UsersColumn
{
    private const COLUMN = 'kalaka';

    /**
     * `manage_users_columns`.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        if (AdminAccess::userIsFull(get_current_user_id())) {
            $columns[self::COLUMN] = esc_html(Texts::COLUMN);
        }

        return $columns;
    }

    /** `manage_users_custom_column`. */
    public static function cell(mixed $output, string $column, int $userId): mixed
    {
        if ($column !== self::COLUMN) {
            return $output;
        }
        $user = get_userdata($userId);

        return $user !== false && ProfileField::holds($user) ? esc_html(Texts::CELL) : '';
    }
}
