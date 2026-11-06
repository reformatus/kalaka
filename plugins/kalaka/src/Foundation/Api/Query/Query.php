<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Query;

use Kalaka\Foundation\Api\Database\DatabaseError;

/**
 * The base of query classes (lists and the evaluation's reads). A query runs statements only
 * through `select()` and `related()`, inside a scope, and each record type once per scope, so a
 * list cannot grow an N+1. Queries return rows, never entities, and use no repository.
 * foundation.md, "Entities, repositories and queries".
 */
abstract class Query
{
    private readonly \wpdb $wpdb;

    /** @var ?array<string, true> the labels used in the open scope; null outside a scope */
    private ?array $used = null;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Runs the work of a public method. Scopes do not nest.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \LogicException
     */
    final protected function scope(\Closure $work): mixed
    {
        if ($this->used !== null) {
            throw new \LogicException('A query scope was opened inside a query scope.');
        }

        $this->used = [];
        try {
            return $work();
        } finally {
            $this->used = null;
        }
    }

    /** A table name with the WordPress prefix. */
    final protected function table(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    /**
     * A prepared `SELECT` under a label naming the record type it loads (`registration`, `count`).
     *
     * @param literal-string $sql
     * @param list<int|string|null> $args
     * @return list<array<string, ?string>>
     * @throws \LogicException outside a scope, or when the label was already used in this scope
     */
    final protected function select(string $label, string $sql, array $args = []): array
    {
        $this->use($label);

        return $this->run($this->prepared($sql, $args));
    }

    /**
     * Loads one record type for a list of keys in one `IN (…)` statement, and nothing for an empty
     * list. `$eventId` adds `event_id = %d AND` for a per-event table, so the statement stays inside
     * the event's index.
     *
     * @param list<int|string> $keys
     * @return list<array<string, ?string>>
     * @throws \LogicException outside a scope, or when the label was already used in this scope
     */
    final protected function related(
        string $label,
        string $table,
        string $column,
        array $keys,
        ?int $eventId = null,
    ): array {
        $this->use($label);
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [];
        }

        $placeholder = array_filter($keys, 'is_int') === $keys ? '%d' : '%s';
        $args = [$this->table($table)];
        if ($eventId !== null) {
            $args[] = $eventId;
        }
        $args[] = $column;
        array_push($args, ...$keys);

        $sql = 'SELECT * FROM %i WHERE ' . ($eventId === null ? '' : 'event_id = %d AND ')
            . '%i IN (' . implode(', ', array_fill(0, count($keys), $placeholder)) . ')';

        return $this->run($this->prepared($sql, $args));
    }

    private function use(string $label): void
    {
        if ($this->used === null) {
            throw new \LogicException('A query statement ran outside a query scope.');
        }
        if (isset($this->used[$label])) {
            $error = new \LogicException('The query loaded "' . $label . '" twice in one scope.');
            throw $error;
        }
        $this->used[$label] = true;
    }

    /**
     * @param literal-string $sql
     * @param list<int|string|null> $args
     */
    private function prepared(string $sql, array $args): string
    {
        if ($args === []) {
            return $sql;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal string by its type
        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            $error = new \LogicException('A query statement could not be prepared.');
            throw $error;
        }

        return $prepared;
    }

    /**
     * @return list<array<string, ?string>>
     */
    private function run(string $sql): array
    {
        // wpdb logs a failed statement with its values; a search may hold personal data.
        $suppressed = $this->wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller
            $rows = $this->wpdb->get_results($sql, ARRAY_A);
        } finally {
            $this->wpdb->suppress_errors($suppressed);
        }
        DatabaseError::check($this->wpdb);

        $result = [];
        foreach ($rows ?? [] as $row) {
            $result[] = array_map(self::text(...), $row);
        }

        return $result;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
