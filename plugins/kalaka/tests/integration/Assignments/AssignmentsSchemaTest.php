<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Assignments;

use Kalaka\Assignments\Module;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** S44: the three tables of the module, keyed event first where they hold per-event rows. */
final class AssignmentsSchemaTest extends WP_UnitTestCase
{
    private const COLUMNS = [
        'kalaka_assignment_sets' => [
            'id' => 'bigint(20) unsigned',
            'event_id' => 'bigint(20) unsigned',
            'version' => 'int(10) unsigned',
            'name' => 'varchar(191)',
            'time_slot' => 'varchar(191) null',
            'required' => 'tinyint(1)',
            'mode' => 'varchar(20)',
            'window_opens_at' => 'datetime(6) null',
            'window_closes_at' => 'datetime(6) null',
            'published' => 'tinyint(1)',
            'request_field_key' => 'varchar(64) null',
        ],
        'kalaka_assignment_options' => [
            'set_id' => 'bigint(20) unsigned',
            'option_key' => 'varchar(64)',
            'event_id' => 'bigint(20) unsigned',
            'version' => 'int(10) unsigned',
            'position' => 'smallint(5) unsigned',
            'label' => 'varchar(191)',
            'capacity' => 'int(10) unsigned null',
            'leaders' => 'varchar(191)',
            'place' => 'varchar(191)',
        ],
        'kalaka_assignments' => [
            'event_id' => 'bigint(20) unsigned',
            'code' => 'varchar(16)',
            'set_id' => 'bigint(20) unsigned',
            'version' => 'int(10) unsigned',
            'option_key' => 'varchar(64)',
            'source' => 'varchar(20)',
            'state' => 'varchar(20)',
        ],
    ];

    /** The keys compared exactly. */
    private const EXACT = [
        'kalaka_assignment_sets.request_field_key' => 'utf8mb4_nopad_bin',
        'kalaka_assignment_options.option_key' => 'utf8mb4_nopad_bin',
        'kalaka_assignments.option_key' => 'utf8mb4_nopad_bin',
    ];

    public function testTheTablesExistWithTheirColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_assignments'));
        self::assertSame([], LoadState::current()->failures);

        foreach (self::COLUMNS as $table => $expected) {
            $columns = $wpdb->get_results($wpdb->prepare(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
                . ' ORDER BY ORDINAL_POSITION',
                $wpdb->prefix . $table
            ), ARRAY_A);
            self::assertIsArray($columns);

            $declared = [];
            foreach ($columns as $column) {
                $declared[$column['COLUMN_NAME']] = $column['COLUMN_TYPE']
                    . ($column['IS_NULLABLE'] === 'YES' ? ' null' : '');
                if ($column['CHARACTER_SET_NAME'] !== null) {
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
