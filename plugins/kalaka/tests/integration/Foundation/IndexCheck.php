<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

/**
 * Compares the indexes a CREATE TABLE statement declares with the ones the database made
 * (engineering.md, "Database": an over-long index silently becomes a prefix or hash index).
 */
final class IndexCheck
{
    /**
     * @return list<string> what differs; empty when every declared index exists as declared
     */
    public static function mismatches(string $table, string $createSql): array
    {
        global $wpdb;

        preg_match_all(
            '/^\s*(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:`?(\w+)`?\s*)?\(([^)]*)\)/m',
            $createSql,
            $declared,
            PREG_SET_ORDER
        );

        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
            $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
        if (!is_array($rows) || $rows === []) {
            return ["{$table}: no indexes found"];
        }

        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['Key_name']][(int) $row['Seq_in_index']] = $row;
        }

        $problems = [];
        foreach ($declared as [, $kind, $name, $columns]) {
            $name = $kind === 'PRIMARY KEY' ? 'PRIMARY' : $name;
            $expected = array_map(static fn (string $c): string => trim($c, " `\t"), explode(',', $columns));
            if (!isset($actual[$name])) {
                $problems[] = "{$table}.{$name}: missing";
                continue;
            }
            ksort($actual[$name]);
            $found = array_map(static fn (array $row): string => (string) $row['Column_name'], $actual[$name]);
            if (array_values($found) !== $expected) {
                $problems[] = "{$table}.{$name}: columns " . implode(',', $found)
                    . ' instead of ' . implode(',', $expected);
            }
            foreach ($actual[$name] as $row) {
                if ($row['Sub_part'] !== null) {
                    $problems[] = "{$table}.{$name}: {$row['Column_name']} is a prefix of {$row['Sub_part']}";
                }
                if ($row['Index_type'] !== 'BTREE') {
                    $problems[] = "{$table}.{$name}: type {$row['Index_type']}";
                }
                if ((int) $row['Non_unique'] !== ($kind === 'KEY' ? 1 : 0)) {
                    $problems[] = "{$table}.{$name}: uniqueness differs";
                }
            }
        }

        return $problems;
    }

    /**
     * Creates a table from the statement and compares its indexes; a table the engine refuses
     * to create (error 1071) counts as a mismatch.
     *
     * @return list<string>
     */
    public static function mismatchesOfNewTable(string $table, string $createSql): array
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        try {
            // A table left by an earlier case would make the create fail with "already exists".
            self::drop($table);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; table name and constants only
            $created = $wpdb->query(str_replace('{table}', $table, $createSql));
            if ($created === false) {
                return ["{$table}: refused: " . $wpdb->last_error];
            }

            return self::mismatches($table, $createSql);
        } finally {
            self::drop($table);
            $wpdb->suppress_errors($suppressed);
        }
    }

    private static function drop(string $table): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    }
}
