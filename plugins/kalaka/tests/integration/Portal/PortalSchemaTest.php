<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Portal\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** The push subscription table of the parked PWA scope. */
final class PortalSchemaTest extends WP_UnitTestCase
{
    private const COLUMNS = [
        'event_id' => 'bigint(20) unsigned',
        'code' => 'varchar(16)',
        'endpoint_hash' => 'varchar(64)',
        'endpoint' => 'longtext',
        'key_p256dh' => 'varchar(128)',
        'key_auth' => 'varchar(32)',
        'created_at' => 'datetime(6)',
    ];

    /** The columns compared exactly; every other text column is `utf8mb4_hungarian_ci`. */
    private const EXACT = ['endpoint_hash', 'endpoint', 'key_p256dh', 'key_auth'];

    public function testTheTableExistsWithItsColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_portal'));
        self::assertSame([], LoadState::current()->failures);

        $name = $wpdb->prefix . 'kalaka_push_subscriptions';
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
                    in_array($column['COLUMN_NAME'], self::EXACT, true) ? 'utf8mb4_nopad_bin' : 'utf8mb4_hungarian_ci',
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

        $statement = str_replace('{prefix}', $wpdb->prefix, (new Module())->schema()->tables[0]);
        self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
        self::assertSame([], IndexCheck::mismatches($match[1], $statement));

        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_push_subscriptions", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            [
                'PRIMARY:event_id:0', 'PRIMARY:code:0', 'PRIMARY:endpoint_hash:0',
                'endpoint_hash:endpoint_hash:1',
            ],
            array_map(
                static fn (array $row): string
                    => $row['Key_name'] . ':' . $row['Column_name'] . ':' . $row['Non_unique'],
                $keys
            )
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
