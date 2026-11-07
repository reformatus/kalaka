<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Repository;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Database\Statements;
use Kalaka\Foundation\LockKey;
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

    private readonly Statements $statements;

    public function __construct()
    {
        $this->statements = new Statements();
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

    /** Whether a duplicate key on the insert is a reference-code collision, which the runner retries. */
    protected function insertsReferenceCode(): bool
    {
        return false;
    }

    /**
     * The field name an audit change item gets for a column.
     *
     * @param TEntity $entity
     */
    protected function auditField(string $column, object $entity): string
    {
        return $column;
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
        $rows = $this->statements->select($this->statements->prepare($sql, [$this->prefixedTable(), ...$args]));

        $entities = [];
        $held = [];
        foreach ($rows as $row) {
            ObjectCache::put($this->table(), $this->keyValues($row), $row);
            $entity = $this->hydrate($row);
            $entities[] = $entity;
            $held[] = [LockKey::normalise($this->keyList($row)), $entity];
        }
        if ($forUpdate !== null && $held !== []) {
            // The database locked the rows in whatever order; each new one must not be below a key held before.
            $table = $this->table();
            usort($held, static fn (array $a, array $b): int => LockKey::compare($table, $a[0], $table, $b[0]));
            foreach ($held as [$key, $entity]) {
                $forUpdate->lockRow($this->lockLevel(), $table, $key, false, $this->heldRegistration($entity));
            }
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

    /** The statements, for the writes of the subclasses in this namespace. */
    final protected function statements(): Statements
    {
        return $this->statements;
    }

    /** Drops the as-loaded copy of an object whose record is gone. */
    final protected function forgetLoaded(object $entity): void
    {
        unset(self::loaded()[$entity]);
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

        // A new row cannot be held by anyone else, so only its level is checked.
        $context->lock($this->lockLevel());
        $insertId = $this->statements->insert($this->prefixedTable(), $data, null, $this->insertsReferenceCode());

        $key = [];
        foreach ($this->keyColumns() as $column) {
            $key[$column] = $data[$column] ?? ($this->autoIncrement() ? $insertId : null);
        }
        $stored = $this->readBack($key);
        $context->lockRow(
            $this->lockLevel(),
            $this->table(),
            $this->keyList($key),
            true,
            $this->heldRegistration($stored),
        );

        $row = self::loaded()[$stored] ?? [];
        $subject = $this->subject($stored);
        if ($recordValues) {
            foreach ($row as $column => $value) {
                if ($column !== 'version' && !in_array($column, $this->keyColumns(), true)) {
                    $context->change(
                        $subject,
                        $this->auditField($column, $stored),
                        null,
                        $value,
                        in_array($column, $this->secretColumns(), true)
                    );
                }
            }
        } else {
            $context->touch($subject);
        }
        $this->markRegistration($stored, $context);

        return $stored;
    }

    /**
     * @param array<string, int|string|null> $key
     * @return TEntity
     */
    final protected function readBack(array $key): object
    {
        $stored = $this->loadByKey($key, false);
        if ($stored === null) {
            throw self::failure('A written record cannot be read back.');
        }

        return $stored;
    }

    /**
     * Tells the run which registration a row it holds maps to, so `Context::holdsRegistration()` knows it.
     *
     * @param TEntity $record
     */
    final protected function holdRegistration(object $record, Context $context): void
    {
        $registration = $this->heldRegistration($record);
        if ($registration !== null) {
            $key = $this->keyList($this->toRow($record));
            $context->lockRow($this->lockLevel(), $this->table(), $key, true, $registration);
        }
    }

    /**
     * The registration a held row's record maps to: only at the registration level.
     *
     * @param TEntity $record
     */
    final protected function heldRegistration(object $record): ?RegistrationKey
    {
        return $this->lockLevel() === LockLevel::Registration ? $this->registrationOf($record) : null;
    }

    /** @param TEntity $stored */
    final protected function markRegistration(object $stored, Context $context): void
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

        return $this->statements->select($this->statements->prepare($sql, $args))[0] ?? null;
    }

    /**
     * The key columns of a row or of a key, in key order.
     *
     * @param array<string, mixed> $source
     * @return array<string, int|string|null>
     */
    final protected function keyValues(array $source): array
    {
        $key = [];
        foreach ($this->keyColumns() as $column) {
            $value = $source[$column] ?? null;
            $key[$column] = is_int($value) || is_string($value) ? $value : null;
        }

        return $key;
    }

    /**
     * The key values of a row or of a key in key order, for the lock guard.
     *
     * @param array<string, mixed> $source
     * @return list<int|string>
     */
    final protected function keyList(array $source): array
    {
        $list = [];
        foreach ($this->keyValues($source) as $value) {
            $list[] = $value ?? '';
        }

        return $list;
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

    final protected function prefixedTable(): string
    {
        return $this->statements->prefixed($this->table());
    }

    /** @return \WeakMap<object, array<string, ?string>> */
    private static function loaded(): \WeakMap
    {
        /** @var \WeakMap<object, array<string, ?string>> */
        return self::$loaded ??= new \WeakMap();
    }

    final protected static function failure(string $message): SystemFailure
    {
        return new SystemFailure(null, new \LogicException($message));
    }
}
