<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Repository;

use Kalaka\Foundation\Api\UseCase\Context;

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
}
