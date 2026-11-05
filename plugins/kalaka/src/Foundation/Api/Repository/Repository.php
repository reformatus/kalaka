<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Repository;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The base of every repository: reads by key, the as-loaded copy that builds the audit diff, and
 * the writes both kinds of repository share. foundation.md, "Entities, repositories and queries".
 *
 * @template TKey
 * @template TEntity of object
 */
abstract class Repository
{
    /** @var ?\WeakMap<object, array<string, ?string>> the stored row of every object a repository returned */
    private static ?\WeakMap $loaded = null;

    private readonly \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /** The table name without the WordPress prefix. */
    abstract protected function table(): string;

    /** @return list<string> */
    abstract protected function keyColumns(): array;

    /** Whether the database generates the key (a single column). */
    abstract protected function autoIncrement(): bool;

    /**
     * @param TKey $key
     * @return array<string, int|string>
     */
    abstract protected function keyToColumns(mixed $key): array;

    /**
     * Every column in its stored form, as the database returns it.
     *
     * @param TEntity $entity
     * @return array<string, int|string|null>
     */
    abstract protected function toRow(object $entity): array;

    /**
     * @param array<string, ?string> $row
     * @return TEntity
     */
    abstract protected function fromRow(array $row): object;

    /** @param TEntity $record */
    abstract protected function subject(object $record): Subject;

    /** @return list<string> columns whose values never reach the audit entry */
    protected function secretColumns(): array
    {
        return [];
    }

    /** @param TEntity $record */
    protected function registrationOf(object $record): ?RegistrationKey
    {
        return null;
    }

    protected function lockLevel(): LockLevel
    {
        return LockLevel::Other;
    }

    /**
     * @param TKey $key
     * @return ?TEntity null when there is no such record
     */
    public function find(mixed $key): ?object
    {
        $columns = $this->keyValues($this->keyToColumns($key));
        $row = ObjectCache::get($this->table(), $columns);
        if ($row !== null) {
            return $this->hydrate($row);
        }

        return $this->loadByKey($columns, false);
    }

    /**
     * A repository's own lookup: `$where` is a prepared-statement condition over this table.
     *
     * @param literal-string $where
     * @param list<int|string|null> $args
     * @return list<TEntity>
     */
    final protected function findWhere(string $where, array $args, ?Context $forUpdate = null): array
    {
        $forUpdate?->lock($this->lockLevel());

        $sql = 'SELECT * FROM %i WHERE ' . $where . ($forUpdate === null ? '' : ' FOR UPDATE');
        $rows = $this->select($this->prepared($sql, [$this->prefixedTable(), ...$args]));

        $entities = [];
        foreach ($rows as $row) {
            ObjectCache::put($this->table(), $this->keyValues($row), $row);
            $entities[] = $this->hydrate($row);
        }

        return $entities;
    }

    /**
     * Reads the record afresh (`FOR UPDATE` when asked), replaces the cached row and returns a new object.
     *
     * @param array<string, int|string|null> $columns
     * @return ?TEntity
     */
    final protected function loadByKey(array $columns, bool $forUpdate): ?object
    {
        $row = $this->readRow($columns, $forUpdate);
        if ($row === null) {
            return null;
        }
        ObjectCache::put($this->table(), $this->keyValues($row), $row);

        return $this->hydrate($row);
    }

    /** @return ?array<string, ?string> the stored row the object was loaded or written as */
    final protected function loadedCopy(object $entity): ?array
    {
        return self::loaded()[$entity] ?? null;
    }

    /**
     * Inserts the record, reads it back and returns the stored object. The audit gets every column
     * but the key and `version` when `$recordValues`, only the subject otherwise.
     *
     * @param TEntity $entity
     * @return TEntity
     */
    final protected function writeInsert(object $entity, Context $context, bool $versioned, bool $recordValues): object
    {
        $data = $this->toRow($entity);
        if ($this->autoIncrement()) {
            // The database generates a key the entity does not carry yet.
            foreach ($this->keyColumns() as $column) {
                if (in_array($data[$column] ?? null, [null, 0, '', '0'], true)) {
                    unset($data[$column]);
                }
            }
        }
        if ($versioned) {
            $data['version'] = 1;
        }

        $written = $this->quietly(fn (): int|false => $this->wpdb->insert($this->prefixedTable(), $data));
        $insertId = $this->wpdb->insert_id;
        DatabaseError::check($this->wpdb);
        if ($written !== 1) {
            throw self::failure('The insert wrote no row.');
        }

        $key = [];
        foreach ($this->keyColumns() as $column) {
            $key[$column] = $data[$column] ?? ($this->autoIncrement() ? $insertId : null);
        }
        $stored = $this->readBack($key);

        $row = self::loaded()[$stored] ?? [];
        $subject = $this->subject($stored);
        if ($recordValues) {
            foreach ($row as $column => $value) {
                if ($column !== 'version' && !in_array($column, $this->keyColumns(), true)) {
                    $context->change($subject, $column, null, $value, in_array($column, $this->secretColumns(), true));
                }
            }
        } else {
            $context->touch($subject);
        }
        $this->markRegistration($stored, $context);

        return $stored;
    }

    /**
     * Writes the columns that differ from the as-loaded copy, if the version is still the one the
     * entity carries, and returns the stored object.
     *
     * @param TEntity $entity
     * @param array<string, ?string> $loaded
     * @return TEntity
     * @throws VersionConflict
     */
    final protected function writeUpdate(object $entity, array $loaded, Context $context): object
    {
        $current = $this->toRow($entity);
        $keyColumns = $this->keyColumns();

        $changed = [];
        foreach ($current as $column => $value) {
            if ($column === 'version') {
                continue;
            }
            $differs = ($value === null ? null : (string) $value) !== ($loaded[$column] ?? null);
            if (in_array($column, $keyColumns, true)) {
                if ($differs) {
                    throw self::failure('A key column of a loaded record was changed.');
                }
                continue;
            }
            if ($differs) {
                $changed[$column] = $value;
            }
        }
        if ($changed === []) {
            return $entity;
        }

        if (!isset($current['version'])) {
            throw self::failure('toRow() gave no version.');
        }
        $expected = (int) $current['version'];
        $where = [];
        foreach ($keyColumns as $column) {
            $where[$column] = $loaded[$column] ?? null;
        }
        $where['version'] = $expected;

        $values = $changed + ['version' => $expected + 1];
        $written = $this->quietly(fn (): int|false => $this->wpdb->update($this->prefixedTable(), $values, $where));
        DatabaseError::check($this->wpdb);
        if ($written === false) {
            throw self::failure('The update wrote no row.');
        }
        if ($written === 0) {
            $error = new VersionConflict();
            throw $error;
        }

        $stored = $this->readBack($this->keyValues($loaded));
        $row = self::loaded()[$stored] ?? [];
        $subject = $this->subject($stored);
        foreach (array_keys($changed) as $column) {
            $context->change(
                $subject,
                $column,
                $loaded[$column] ?? null,
                $row[$column] ?? null,
                in_array($column, $this->secretColumns(), true)
            );
        }
        $this->markRegistration($stored, $context);

        return $stored;
    }

    /**
     * @param array<string, int|string|null> $key
     * @return TEntity
     */
    private function readBack(array $key): object
    {
        $stored = $this->loadByKey($key, false);
        if ($stored === null) {
            throw self::failure('A written record cannot be read back.');
        }

        return $stored;
    }

    /** @param TEntity $stored */
    private function markRegistration(object $stored, Context $context): void
    {
        $registration = $this->registrationOf($stored);
        if ($registration !== null) {
            $context->markTouched($registration);
        }
    }

    /**
     * @param array<string, int|string|null> $columns
     * @return ?array<string, ?string>
     */
    private function readRow(array $columns, bool $forUpdate): ?array
    {
        $conditions = [];
        $args = [$this->prefixedTable()];
        foreach ($columns as $column => $value) {
            $conditions[] = '%i = ' . (is_int($value) ? '%d' : '%s');
            $args[] = $column;
            $args[] = $value;
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $conditions) . ($forUpdate ? ' FOR UPDATE' : '');

        return $this->select($this->prepared($sql, $args))[0] ?? null;
    }

    /**
     * @param literal-string $sql
     * @param list<int|string|null> $args
     */
    private function prepared(string $sql, array $args): string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a literal string by its type
        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            throw self::failure('A statement could not be prepared.');
        }

        return $prepared;
    }

    /** @return list<array<string, ?string>> */
    private function select(string $sql): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller
        $rows = $this->quietly(fn (): mixed => $this->wpdb->get_results($sql, ARRAY_A));
        DatabaseError::check($this->wpdb);

        $result = [];
        foreach ($rows ?? [] as $row) {
            $result[] = array_map(self::text(...), $row);
        }

        return $result;
    }

    /**
     * Runs a statement without wpdb's error output, which would show the statement's values.
     *
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

    /**
     * The key columns of a row or of a key, in key order.
     *
     * @param array<string, mixed> $source
     * @return array<string, int|string|null>
     */
    private function keyValues(array $source): array
    {
        $key = [];
        foreach ($this->keyColumns() as $column) {
            $value = $source[$column] ?? null;
            $key[$column] = is_int($value) || is_string($value) ? $value : null;
        }

        return $key;
    }

    /**
     * @param array<string, ?string> $row
     * @return TEntity
     */
    private function hydrate(array $row): object
    {
        $entity = $this->fromRow($row);
        self::loaded()[$entity] = $row;

        return $entity;
    }

    private function prefixedTable(): string
    {
        return $this->wpdb->prefix . $this->table();
    }

    /** @return \WeakMap<object, array<string, ?string>> */
    private static function loaded(): \WeakMap
    {
        /** @var \WeakMap<object, array<string, ?string>> */
        return self::$loaded ??= new \WeakMap();
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
