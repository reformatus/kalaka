<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Repository;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\ObjectCache;

/**
 * The base of repositories of mutable records: a `version` column, a write that checks it, and
 * the audit diff against the object as loaded. foundation.md, "Entities, repositories and queries".
 *
 * @template TKey
 * @template TEntity of object
 * @extends Repository<TKey, TEntity>
 */
abstract class EntityRepository extends Repository
{
    /**
     * Reads the record `FOR UPDATE`, after declaring the repository's lock level to the run.
     *
     * @param TKey $key
     * @return ?TEntity
     */
    public function findForUpdate(mixed $key, Context $context): ?object
    {
        $context->lock($this->lockLevel());

        return $this->loadByKey($this->keyToColumns($key), true);
    }

    /**
     * Inserts an entity that was not loaded and updates one that was. Returns the stored object,
     * the one to keep: the given object keeps its old version.
     *
     * @param TEntity $entity
     * @return TEntity
     * @throws \Kalaka\Foundation\Api\Error\VersionConflict when the stored version is not the one the entity carries
     */
    public function upsert(object $entity, Context $context): object
    {
        $context->assertOpen();
        $loaded = $this->loadedCopy($entity);

        return $loaded === null
            ? $this->writeInsert($entity, $context, true, true)
            : $this->writeUpdate($entity, $loaded, $context);
    }

    /**
     * Deletes the record of a loaded entity if the version is still the one the entity carries.
     *
     * @param TEntity $entity
     * @throws \Kalaka\Foundation\Api\Error\VersionConflict when no row matches the key and version
     */
    public function delete(object $entity, Context $context): void
    {
        $context->assertOpen();
        $loaded = $this->loadedCopy($entity);
        if ($loaded === null) {
            $error = new SystemFailure(null, new \LogicException('Only a loaded entity can be deleted.'));
            throw $error;
        }

        $this->writeDelete($entity, $loaded, $context);
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
        $written = $this->statements()->update($this->prefixedTable(), $values, $where);
        if ($written === 0) {
            $error = new VersionConflict();
            throw $error;
        }

        $stored = $this->readBack($this->keyValues($loaded));
        $row = $this->loadedCopy($stored) ?? [];
        $subject = $this->subject($stored);
        foreach (array_keys($changed) as $column) {
            $context->change(
                $subject,
                $this->auditField($column, $stored),
                $loaded[$column] ?? null,
                $row[$column] ?? null,
                in_array($column, $this->secretColumns(), true)
            );
        }
        $this->markRegistration($stored, $context);

        return $stored;
    }

    /**
     * Deletes the row by key and the carried version, and records every column but the key and
     * `version` as changed from its as-loaded value to null.
     *
     * @param TEntity $entity
     * @param array<string, ?string> $loaded
     * @throws VersionConflict
     */
    final protected function writeDelete(object $entity, array $loaded, Context $context): void
    {
        $current = $this->toRow($entity);
        if (!isset($current['version'])) {
            throw self::failure('toRow() gave no version.');
        }

        $where = [];
        foreach ($this->keyColumns() as $column) {
            $where[$column] = $loaded[$column] ?? null;
        }
        $where['version'] = (int) $current['version'];

        $written = $this->statements()->delete($this->prefixedTable(), $where);
        if ($written === 0) {
            $error = new VersionConflict();
            throw $error;
        }

        $key = $this->keyValues($loaded);
        ObjectCache::forget($this->table(), $key);
        $this->forgetLoaded($entity);

        $deleted = $this->fromRow($loaded);
        $subject = $this->subject($deleted);
        foreach ($loaded as $column => $value) {
            if ($column !== 'version' && !in_array($column, $this->keyColumns(), true)) {
                $context->change(
                    $subject,
                    $this->auditField($column, $deleted),
                    $value,
                    null,
                    in_array($column, $this->secretColumns(), true)
                );
            }
        }
        $this->markRegistration($deleted, $context);
    }
}
