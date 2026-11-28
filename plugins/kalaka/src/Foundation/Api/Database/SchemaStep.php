<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Database;

use Kalaka\Foundation\Database\Statements;

/**
 * What a module's schema step may do that dbDelta cannot (`Schema::$steps`): drop a column. A step runs inside
 * the schema upgrade's lock and is safe to run again.
 */
final class SchemaStep
{
    /**
     * Drops the column of one of the module's own tables (`$table` without the WordPress prefix) when it has it.
     */
    public static function dropColumnIfExists(string $table, string $column): void
    {
        $statements = new Statements();
        $statements->dropColumnIfExists($statements->prefixed($table), $column);
    }
}
