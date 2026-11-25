<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Support\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** AC1: the plugin loads with core and creates the directory table. */
final class SupportSchemaTest extends WP_UnitTestCase
{
    public function testTheTableExistsWithItsIndexesAndTheDeclaredCharacterSetAndCollation(): void
    {
        global $wpdb;

        self::assertTrue(LoadState::current()->isOpen());
        self::assertSame(2, (int) get_option('kalaka_schema_support'));

        $table = $wpdb->prefix . 'kalaka_support_nodes';
        $tables = (new Module())->schema()->tables;
        self::assertCount(2, $tables);
        self::assertSame([], IndexCheck::mismatches($table, str_replace('{prefix}', $wpdb->prefix, $tables[0])));
        self::assertSame([], IndexCheck::mismatches(
            $wpdb->prefix . 'kalaka_support_offers',
            str_replace('{prefix}', $wpdb->prefix, $tables[1])
        ));

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

        $offerColumns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $wpdb->prefix . 'kalaka_support_offers'
        ), ARRAY_A);
        self::assertIsArray($offerColumns);
        $offers = [];
        foreach ($offerColumns as $column) {
            $offers[$column['COLUMN_NAME']] = [
                $column['COLUMN_TYPE'],
                $column['CHARACTER_SET_NAME'],
                $column['COLLATION_NAME'],
            ];
        }
        self::assertSame(
            [
                'event_id' => ['bigint(20) unsigned', null, null],
                'offer_key' => ['varchar(40)', 'utf8mb4', 'utf8mb4_nopad_bin'],
                'position' => ['int(10) unsigned', null, null],
                'label' => ['varchar(191)', 'utf8mb4', 'utf8mb4_hungarian_ci'],
                'node_id' => ['bigint(20) unsigned', null, null],
                'amount' => ['decimal(26,8)', null, null],
                'currency' => ['char(3)', 'utf8mb4', 'utf8mb4_nopad_bin'],
                'needs_request' => ['tinyint(1) unsigned', null, null],
                'seat_limit' => ['int(10) unsigned', null, null],
                'version' => ['int(10) unsigned', null, null],
            ],
            $offers
        );
    }
}
