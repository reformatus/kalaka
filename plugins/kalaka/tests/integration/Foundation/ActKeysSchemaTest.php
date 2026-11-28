<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use WP_UnitTestCase;

/** The act key table: version, columns, collation, the time column and the unique key. */
final class ActKeysSchemaTest extends WP_UnitTestCase
{
    public function testTheTableHasTheDeclaredColumnsAndTheUniqueKey(): void
    {
        global $wpdb;

        self::assertSame(4, (int) get_option('kalaka_schema_foundation'));

        $name = $wpdb->prefix . 'kalaka_act_keys';
        self::assertSame('utf8mb4_hungarian_ci', $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $name
        )));

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $name
        ), ARRAY_A);
        self::assertIsArray($columns);
        self::assertSame(
            [
                'id' => 'bigint(20) unsigned',
                'event_id' => 'bigint(20) unsigned',
                'code' => 'varchar(16)',
                'act' => 'varchar(191)',
                'idempotency_key' => 'varchar(128)',
                'input_hash' => 'char(64)',
                'result' => 'longtext',
                'created_at' => 'datetime(6)',
            ],
            array_column($columns, 'COLUMN_TYPE', 'COLUMN_NAME')
        );

        $unique = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> %s'
            . ' ORDER BY SEQ_IN_INDEX',
            $name,
            'PRIMARY'
        ));
        self::assertSame(['event_id', 'code', 'idempotency_key'], $unique);
    }
}
