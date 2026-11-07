<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Registrations\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** AC1: the three tables of the module. */
final class RegistrationsSchemaTest extends WP_UnitTestCase
{
    private const COLUMNS = [
        'kalaka_registrations' => [
            'event_id' => 'bigint(20) unsigned',
            'code' => 'varchar(16)',
            'version' => 'int(10) unsigned',
            'status' => 'varchar(20)',
            'secret' => 'varchar(22)',
            'created_at' => 'datetime(6)',
            'registered_at' => 'datetime(6) null',
        ],
        'kalaka_field_values' => [
            'event_id' => 'bigint(20) unsigned',
            'code' => 'varchar(16)',
            'field_key' => 'varchar(64)',
            'version' => 'int(10) unsigned',
            'value' => 'longtext',
        ],
        'kalaka_submissions' => [
            'event_id' => 'bigint(20) unsigned',
            'code' => 'varchar(16)',
            'number' => 'int(10) unsigned',
            'form_id' => 'bigint(20) unsigned',
            'form_version' => 'bigint(20) unsigned',
            'answers' => 'longtext',
            'submitted_at' => 'datetime(6)',
        ],
    ];

    /** The columns compared exactly (#42, D1). */
    private const EXACT = [
        'kalaka_registrations.secret' => 'utf8mb4_nopad_bin',
        'kalaka_field_values.field_key' => 'utf8mb4_nopad_bin',
    ];

    public function testTheTablesExistWithTheirColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_registrations'));
        self::assertSame([], LoadState::current()->failures);

        foreach (self::COLUMNS as $table => $expected) {
            $name = $wpdb->prefix . $table;
            self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $name
            )), $table);

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
                    self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $table . '.' . $column['COLUMN_NAME']);
                    self::assertSame(
                        self::EXACT[$table . '.' . $column['COLUMN_NAME']] ?? 'utf8mb4_hungarian_ci',
                        $column['COLLATION_NAME'],
                        $table . '.' . $column['COLUMN_NAME']
                    );
                }
            }
            self::assertSame($expected, $declared, $table);
        }
    }

    public function testTheKeysAreAsDeclaredWithoutAPrefixIndex(): void
    {
        global $wpdb;

        $tables = (new Module())->schema()->tables;
        self::assertCount(3, $tables);
        foreach ($tables as $statement) {
            $statement = str_replace('{prefix}', $wpdb->prefix, $statement);
            self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
            self::assertSame([], IndexCheck::mismatches($match[1], $statement));
        }

        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_registrations", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            ['PRIMARY:event_id', 'PRIMARY:code', 'code:code'],
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
