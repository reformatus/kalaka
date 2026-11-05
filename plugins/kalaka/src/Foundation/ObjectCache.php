<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

/**
 * The per-request object cache of the repositories: it holds stored rows, keyed by table and key,
 * never objects. The runner clears it after every rollback and when an outermost attempt begins
 * (foundation.md, "Entities, repositories and queries" and "The use-case runner").
 *
 * @internal
 */
final class ObjectCache
{
    /** @var array<string, array<string, ?string>> */
    private static array $rows = [];

    /**
     * @param array<string, int|string|null> $key the key columns and values
     * @return ?array<string, ?string>
     */
    public static function get(string $table, array $key): ?array
    {
        return self::$rows[self::identity($table, $key)] ?? null;
    }

    /**
     * @param array<string, int|string|null> $key
     * @param array<string, ?string> $row
     */
    public static function put(string $table, array $key, array $row): void
    {
        self::$rows[self::identity($table, $key)] = $row;
    }

    public static function clear(): void
    {
        self::$rows = [];
    }

    /** @param array<string, int|string|null> $key */
    private static function identity(string $table, array $key): string
    {
        return $table . "\0" . implode("\0", array_map('strval', $key));
    }
}
