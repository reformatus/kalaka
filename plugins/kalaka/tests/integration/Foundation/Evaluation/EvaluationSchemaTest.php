<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\LoadState;
use WP_UnitTestCase;

/** The decision and flag tables: version, character set, collation and time columns. */
final class EvaluationSchemaTest extends WP_UnitTestCase
{
    public function testTheTablesExistWithTheDeclaredColumns(): void
    {
        global $wpdb;

        self::assertSame(3, (int) get_option('kalaka_schema_foundation'));
        self::assertSame([], LoadState::current()->failures);

        $expected = [
            'kalaka_decisions' => [
                'id', 'event_id', 'code', 'kind', 'state', 'effective_at', 'recorded_at', 'cause_type',
                'cause_event_id', 'cause_key', 'actor_type', 'actor_user_id', 'actor_event_id', 'actor_code',
            ],
            'kalaka_evaluation_failures' => ['event_id', 'code', 'version', 'rule', 'error', 'failed_at'],
        ];
        foreach ($expected as $table => $columns) {
            $name = $wpdb->prefix . $table;
            $collation = $wpdb->get_var($wpdb->prepare(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $name
            ));
            self::assertSame('utf8mb4_hungarian_ci', $collation, $name);

            $found = $wpdb->get_results($wpdb->prepare(
                'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, CHARACTER_SET_NAME, COLLATION_NAME'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
                . ' ORDER BY ORDINAL_POSITION',
                $name
            ), ARRAY_A);
            self::assertIsArray($found);
            self::assertSame($columns, array_column($found, 'COLUMN_NAME'), $name);
            foreach ($found as $column) {
                if (in_array($column['DATA_TYPE'], ['varchar', 'text', 'longtext'], true)) {
                    self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $name . '.' . $column['COLUMN_NAME']);
                    self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $name . '.' . $column['COLUMN_NAME']);
                }
                if (str_ends_with((string) $column['COLUMN_NAME'], '_at')) {
                    self::assertSame('datetime(6)', $column['COLUMN_TYPE'], $name . '.' . $column['COLUMN_NAME']);
                }
            }
        }
    }
}
