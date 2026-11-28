<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\LockKey;

/**
 * The order the runner's lock guard demands of rows at one level: by table name, then column by column, with
 * integers compared as numbers (foundation.md, "Lock order"). For a use case that must lock several rows of one
 * level, so it takes them in the order the guard accepts instead of sorting by a string of its own.
 */
final class LockOrder
{
    /**
     * Negative when the first row sorts before the second. A key's integers may be given as numeric strings.
     *
     * @param list<int|string> $a the first row's key columns
     * @param list<int|string> $b the second row's key columns
     * @return int<-1, 1>
     */
    public static function compare(string $tableA, array $a, string $tableB, array $b): int
    {
        return LockKey::compare($tableA, LockKey::normalise($a), $tableB, LockKey::normalise($b));
    }
}
