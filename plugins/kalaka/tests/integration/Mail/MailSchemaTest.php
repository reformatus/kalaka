<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Mail\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** The Mail tables. */
final class MailSchemaTest extends WP_UnitTestCase
{
    private const DELIVERIES = [
        'id' => 'bigint(20) unsigned',
        'event_id' => 'bigint(20) unsigned',
        'mail' => 'varchar(64)',
        'code' => 'varchar(16)',
        'cause_type' => 'varchar(50)',
        'cause_key' => 'varchar(64)',
        'version' => 'int(10) unsigned',
        'kind' => 'varchar(20)',
        'status' => 'varchar(20)',
        'attempts' => 'int(10) unsigned',
        'queued_at' => 'datetime(6)',
        'claimed_at' => 'datetime(6) null',
        'sent_at' => 'datetime(6) null',
        'mail_version' => 'varchar(64) null',
        'error' => 'longtext null',
    ];

    private const STATES = [
        'id' => 'tinyint(3) unsigned',
        'version' => 'int(10) unsigned',
        'paused_at' => 'datetime(6) null',
        'cause' => 'varchar(20) null',
        'paused_by' => 'bigint(20) unsigned null',
        'delivery_id' => 'bigint(20) unsigned null',
        'delivery_event_id' => 'bigint(20) unsigned null',
        'error' => 'longtext null',
        'errors_in_row' => 'int(10) unsigned',
        'hourly_budget' => 'int(10) unsigned',
    ];

    public function testTheTablesExistWithTheirColumnsAndTheDeclaredCollation(): void
    {
        self::assertSame(2, (int) get_option('kalaka_schema_mail'));
        self::assertSame([], LoadState::current()->failures);
        self::assertSame(self::DELIVERIES, $this->columns('kalaka_deliveries'));
        self::assertSame(self::STATES, $this->columns('kalaka_mail_states'));
    }

    public function testTheKeysAreAsDeclaredWithoutAPrefixIndex(): void
    {
        global $wpdb;
        foreach ((new Module())->schema()->tables as $table) {
            $statement = str_replace('{prefix}', $wpdb->prefix, $table);
            self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
            self::assertSame([], IndexCheck::mismatches($match[1], $statement));
        }
        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_deliveries", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            [
                'PRIMARY:id', 'once:event_id', 'once:mail', 'once:code', 'once:cause_type', 'once:cause_key',
                'event_status:event_id', 'event_status:status', 'claimed_at:claimed_at',
                'status_id:status', 'status_id:id',
            ],
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

    /** @return array<string, string> */
    private function columns(string $table): array
    {
        global $wpdb;
        $name = $wpdb->prefix . $table;
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
                self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $column['COLUMN_NAME']);
            }
        }

        return $declared;
    }
}
