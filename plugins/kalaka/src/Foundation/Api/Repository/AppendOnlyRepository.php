<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Repository;

use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The base of repositories of append-only records (ledger lines, submissions): inserted once,
 * never updated. The record is its own history, so the audit gets its subject and no values.
 * foundation.md, "Entities, repositories and queries".
 *
 * @template TKey
 * @template TEntity of object
 * @extends Repository<TKey, TEntity>
 */
abstract class AppendOnlyRepository extends Repository
{
    /**
     * @param TEntity $record
     * @return TEntity the stored record, with its generated ID
     */
    public function insert(object $record, Context $context): object
    {
        $context->assertOpen();

        return $this->writeInsert($record, $context, false, false);
    }
}
