<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Database;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The only class that runs statements on `$wpdb`: the runner's transactions, the repository and
 * query bases, the audit writer and the development drop go through it. Every statement runs with
 * wpdb's error output suppressed, which would show the statement's values, and is followed by
 * `DatabaseError::check()`. `$table` arguments are full, prefixed table names.
 * engineering.md, "Static checks".
 */
final class Statements
{
    /** The savepoints the runner uses. */
    private const SAVEPOINTS = ['kalaka_run', 'kalaka_audit'];

    private readonly \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /** Only the runner begins, commits and rolls back. */
    public function begin(): void
    {
        $this->run('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->run('COMMIT');
    }

    public function rollback(): void
    {
        $this->run('ROLLBACK');
    }

    public function savepoint(string $name): void
    {
        $this->run('SAVEPOINT ' . self::savepointName($name));
    }

    public function releaseSavepoint(string $name): void
    {
        $this->run('RELEASE SAVEPOINT ' . self::savepointName($name));
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->run('ROLLBACK TO SAVEPOINT ' . self::savepointName($name));
    }

    /**
     * @param literal-string $sql
     * @param list<int|string|null> $args none: `$sql` as it is
     * @throws SystemFailure when wpdb cannot prepare it
     */
    public function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            return $sql;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal string by its type
        $prepared = $this->wpdb->prepare($sql, ...$args);
        // wpdb answers a mismatch between placeholders and arguments with ''.
        if (!is_string($prepared) || $prepared === '') {
            throw self::failure('A statement could not be prepared.');
        }

        return $prepared;
    }

    /**
     * @param string $prepared a statement from prepare()
     * @return list<array<string, ?string>> every value as a string, or null
     */
    public function select(string $prepared): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller
        $rows = $this->quietly(fn (): mixed => $this->wpdb->get_results($prepared, ARRAY_A));
        DatabaseError::check($this->wpdb);

        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $result[] = array_map(self::text(...), (array) $row);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param ?list<string> $formats wpdb's formats; null: wpdb's own
     * @param bool $referenceCode a duplicate key is a reference-code collision, which the runner retries
     * @return int the insert id
     */
    public function insert(string $table, array $row, ?array $formats = null, bool $referenceCode = false): int
    {
        $written = $this->quietly(fn (): int|false => $this->wpdb->insert($table, $row, $formats));
        $insertId = $this->wpdb->insert_id;
        DatabaseError::check($this->wpdb, $referenceCode);
        if ($written !== 1) {
            throw self::failure('The insert wrote no row.');
        }

        return $insertId;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     * @return int the rows affected
     */
    public function update(string $table, array $values, array $where): int
    {
        $written = $this->quietly(fn (): int|false => $this->wpdb->update($table, $values, $where));
        DatabaseError::check($this->wpdb);
        if ($written === false) {
            throw self::failure('The update wrote no row.');
        }

        return $written;
    }

    /**
     * @param array<string, mixed> $where
     * @return int the rows affected
     */
    public function delete(string $table, array $where): int
    {
        $written = $this->quietly(fn (): int|false => $this->wpdb->delete($table, $where));
        DatabaseError::check($this->wpdb);
        if ($written === false) {
            throw self::failure('The delete wrote no row.');
        }

        return $written;
    }

    /** Only the development drop drops tables. */
    public function dropTableIfExists(string $table): void
    {
        $this->run($this->prepare('DROP TABLE IF EXISTS %i', [$table]));
    }

    /** A table name with the WordPress prefix. */
    public function prefixed(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    /** The connection's thread id, read without a statement; null when there is no connection. */
    public function threadId(): ?int
    {
        // `dbh` is protected, but wpdb exposes it through __get().
        $connection = $this->wpdb->__get('dbh');

        return $connection instanceof \mysqli ? $connection->thread_id : null;
    }

    private function run(string $sql): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constants, listed savepoint names, or prepared
        $this->quietly(fn (): mixed => $this->wpdb->query($sql));
        DatabaseError::check($this->wpdb);
    }

    /**
     * @template T
     * @param \Closure(): T $statement
     * @return T
     */
    private function quietly(\Closure $statement): mixed
    {
        $suppressed = $this->wpdb->suppress_errors(true);
        try {
            return $statement();
        } finally {
            $this->wpdb->suppress_errors($suppressed);
        }
    }

    private static function savepointName(string $name): string
    {
        if (!in_array($name, self::SAVEPOINTS, true)) {
            $error = self::failure('Unknown savepoint "' . $name . '".');
            throw $error;
        }

        return $name;
    }

    private static function failure(string $message): SystemFailure
    {
        return new SystemFailure(null, new \LogicException($message));
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
