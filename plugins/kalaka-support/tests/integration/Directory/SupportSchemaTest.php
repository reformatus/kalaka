<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Support\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

require_once __DIR__ . '/../../../../kalaka/tests/integration/Foundation/IndexCheck.php';

/** AC1: the plugin loads with core and creates the directory table. */
final class SupportSchemaTest extends WP_UnitTestCase
{
    public function testTheTableExistsWithItsIndexesAndTheDeclaredCharacterSetAndCollation(): void
    {
        global $wpdb;

        self::assertTrue(LoadState::current()->isOpen());
        self::assertSame(1, (int) get_option('kalaka_schema_support'));

        $table = $wpdb->prefix . 'kalaka_support_nodes';
        $tables = (new Module())->schema()->tables;
        self::assertCount(1, $tables);
        self::assertSame([], IndexCheck::mismatches($table, str_replace('{prefix}', $wpdb->prefix, $tables[0])));

        self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        )));

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $table
        ), ARRAY_A);
        self::assertIsArray($columns);
        $text = [];
        foreach ($columns as $column) {
            if ($column['CHARACTER_SET_NAME'] === null) {
                continue;
            }
            $text[] = $column['COLUMN_NAME'];
            if ($column['COLUMN_NAME'] === 'external_id') {
                self::assertSame('varchar(255)', $column['COLUMN_TYPE']);
                self::assertSame('ascii', $column['CHARACTER_SET_NAME']);
                self::assertSame('ascii_bin', $column['COLLATION_NAME']);
                continue;
            }
            self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $column['COLUMN_NAME']);
            self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $column['COLUMN_NAME']);
        }
        self::assertSame(['external_id', 'type', 'name', 'aliases'], $text);
        self::assertSame(
            ['id', 'external_id', 'parent_id', 'type', 'name', 'aliases', 'active', 'version'],
            array_column($columns, 'COLUMN_NAME')
        );
    }
}
