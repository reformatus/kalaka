<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Ledger\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** AC1: the ledger table. */
final class LedgerSchemaTest extends WP_UnitTestCase
{
    private const COLUMNS = [
        'id' => 'bigint(20) unsigned',
        'event_id' => 'bigint(20) unsigned',
        'code' => 'varchar(16)',
        'kind' => 'varchar(20)',
        'payer' => 'varchar(50)',
        'amount' => 'decimal(26,8)',
        'currency' => 'char(3)',
        'source_type' => 'varchar(50)',
        'source_event_id' => 'bigint(20) unsigned null',
        'source_key' => 'varchar(64)',
        'idempotency_key' => 'varchar(128)',
        'value_date' => 'date null',
        'paid_at' => 'datetime(6) null',
        'recorded_at' => 'datetime(6)',
    ];

    public function testTheTableExistsWithItsColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_ledger'));
        self::assertSame([], LoadState::current()->failures);

        $name = $wpdb->prefix . 'kalaka_ledger_lines';
        self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $name
        )));

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
            . ' ORDER BY ORDINAL_POSITION',
            $name
        ), ARRAY_A);
        self::assertIsArray($columns);

        $declared = [];
        foreach ($columns as $column) {
            $declared[$column['COLUMN_NAME']] = $column['COLUMN_TYPE']
                . ($column['IS_NULLABLE'] === 'YES' ? ' null' : '');
            if ($column['CHARACTER_SET_NAME'] !== null) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $column['COLUMN_NAME']);
                self::assertSame(
                    $column['COLUMN_NAME'] === 'currency' ? 'utf8mb4_nopad_bin' : 'utf8mb4_hungarian_ci',
                    $column['COLLATION_NAME'],
                    $column['COLUMN_NAME']
                );
            }
        }
        self::assertSame(self::COLUMNS, $declared);
    }

    public function testTheKeysAreAsDeclaredWithoutAPrefixIndex(): void
    {
        global $wpdb;

        $tables = (new Module())->schema()->tables;
        self::assertCount(1, $tables);
        $statement = str_replace('{prefix}', $wpdb->prefix, $tables[0]);
        self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
        self::assertSame([], IndexCheck::mismatches($match[1], $statement));

        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_ledger_lines", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            ['PRIMARY:id', 'idempotency_key:event_id', 'idempotency_key:code', 'idempotency_key:idempotency_key'],
            array_map(static fn (array $row): string => $row['Key_name'] . ':' . $row['Column_name'], $keys)
        );
    }

    public function testDbDeltaFindsNothingToChange(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $statements = array_map(
            static fn (string $statement): string => str_replace('{prefix}', $wpdb->prefix, $statement),
            (new Module())->schema()->tables
        );

        self::assertSame([], dbDelta($statements, false));
    }
}
