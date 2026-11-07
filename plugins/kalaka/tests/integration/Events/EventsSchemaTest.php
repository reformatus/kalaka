<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Foundation\Api\LoadState;
use WP_UnitTestCase;

/** AC1: the events table. The index comparison of every module's schema is AuditSchemaTest's. */
final class EventsSchemaTest extends WP_UnitTestCase
{
    public function testTheTableExistsWithItsColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(2, (int) get_option('kalaka_schema_events'));
        self::assertSame([], LoadState::current()->failures);

        $name = $wpdb->prefix . 'kalaka_events';
        self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $name
        )));

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $name
        ), ARRAY_A);
        self::assertIsArray($columns);

        $declared = [];
        foreach ($columns as $column) {
            $declared[$column['COLUMN_NAME']] = $column['COLUMN_TYPE'] . ($column['IS_NULLABLE'] === 'YES' ? ' null' : '');
            if ($column['CHARACTER_SET_NAME'] !== null) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $column['COLUMN_NAME']);
                self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $column['COLUMN_NAME']);
            }
        }

        self::assertSame(
            [
                'id' => 'bigint(20) unsigned',
                'version' => 'int(10) unsigned',
                'name' => 'varchar(191)',
                'state' => 'varchar(20)',
                'capacity' => 'int(10) unsigned null',
                'is_full' => 'tinyint(1)',
                'registration_opens_at' => 'datetime(6) null',
                'registration_closes_at' => 'datetime(6) null',
                'starts_at' => 'datetime(6) null',
                'ends_at' => 'datetime(6) null',
                'points' => 'longtext',
                'next_point_id' => 'int(10) unsigned',
                'archived_at' => 'datetime(6) null',
            ],
            $declared
        );
    }
}
