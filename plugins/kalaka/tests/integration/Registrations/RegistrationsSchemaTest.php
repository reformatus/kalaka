<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\SchemaUpgrader;
use Kalaka\Registrations\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** AC1: the tables of the module (S74 adds the drafts, schema 4, after S15's 3). */
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
            'owner_user_id' => 'bigint(20) unsigned null',
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
            'submit_token' => 'varchar(22) null',
            'answers_hash' => 'varchar(64) null',
        ],
        'kalaka_drafts' => [
            'event_id' => 'bigint(20) unsigned',
            'id' => 'bigint(20) unsigned',
            'version' => 'int(10) unsigned',
            'claims' => 'int(10) unsigned',
            'form_id' => 'bigint(20) unsigned',
            'form_version' => 'bigint(20) unsigned',
            'user_id' => 'bigint(20) unsigned null',
            'claim_hash' => 'char(64) null',
            'resolved_claim' => 'char(64) null',
            'resolved_choice' => 'varchar(16) null',
            'answers' => 'longtext',
            'page' => 'smallint(5) unsigned',
            'created_at' => 'datetime(6)',
            'updated_at' => 'datetime(6)',
        ],
    ];

    /** The columns compared exactly (#42, D1). */
    private const EXACT = [
        'kalaka_registrations.secret' => 'utf8mb4_nopad_bin',
        'kalaka_field_values.field_key' => 'utf8mb4_nopad_bin',
        'kalaka_submissions.submit_token' => 'utf8mb4_nopad_bin',
        'kalaka_submissions.answers_hash' => 'utf8mb4_nopad_bin',
        'kalaka_drafts.claim_hash' => 'utf8mb4_nopad_bin',
        'kalaka_drafts.resolved_claim' => 'utf8mb4_nopad_bin',
        'kalaka_drafts.resolved_choice' => 'utf8mb4_nopad_bin',
    ];

    public function testTheTablesExistWithTheirColumnsAndTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(4, (int) get_option('kalaka_schema_registrations'));
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
        self::assertCount(4, $tables);
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

        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_submissions", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            [
                'PRIMARY:event_id:0', 'PRIMARY:code:0', 'PRIMARY:number:0',
                'submit_token:event_id:0', 'submit_token:submit_token:0',
            ],
            array_map(
                static fn (array $row): string
                    => $row['Key_name'] . ':' . $row['Column_name'] . ':' . $row['Non_unique'],
                $keys
            )
        );
    }

    public function testADraftIsKeyedEventFirstWithOneDraftPerFormAndPersonAndOneClaim(): void
    {
        global $wpdb;

        $keys = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}kalaka_drafts", ARRAY_A);
        self::assertIsArray($keys);
        self::assertSame(
            [
                'PRIMARY:event_id:0', 'PRIMARY:id:0',
                'owner:event_id:0', 'owner:form_id:0', 'owner:user_id:0',
                'claim:claim_hash:0', 'id:id:1',
            ],
            array_map(
                static fn (array $row): string
                    => $row['Key_name'] . ':' . $row['Column_name'] . ':' . $row['Non_unique'],
                $keys
            )
        );
    }

    /**
     * A site at schema 3 (S15's `owner_user_id`, no drafts) upgrades to 4: `kalaka_drafts` is created and the
     * registrations keep their owner. Real tables under a prefix of their own, dropped after; creating them
     * commits the harness transaction, so the version option is put back by hand.
     */
    public function testAnUpgradeFromThreeAddsTheDraftsAndKeepsTheOwnerAccount(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        $module = new Module();
        $upgrader = new SchemaUpgrader();
        $option = $upgrader->versionOption($module);
        $prefix = $wpdb->prefix;
        $wpdb->prefix = $prefix . 'upgrade_';
        try {
            foreach (array_slice($module->schema()->tables, 0, 3) as $statement) {
                dbDelta(str_replace('{prefix}', $wpdb->prefix, $statement));
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (event_id, code, version, status, secret, created_at, owner_user_id)'
                . " VALUES (1, 'TESZT1', 1, 'registered', %s, '2026-11-19 10:00:00', 7)",
                $wpdb->prefix . 'kalaka_registrations',
                str_repeat('a', 22)
            ));
            self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'kalaka_drafts')));
            update_option($option, 3);

            $upgrader->upgrade($module);

            self::assertSame(4, (int) get_option($option));
            self::assertSame(
                $wpdb->prefix . 'kalaka_drafts',
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'kalaka_drafts'))
            );
            self::assertSame('7', $wpdb->get_var($wpdb->prepare(
                "SELECT owner_user_id FROM %i WHERE code = 'TESZT1'",
                $wpdb->prefix . 'kalaka_registrations'
            )));
        } finally {
            foreach (['kalaka_registrations', 'kalaka_field_values', 'kalaka_submissions', 'kalaka_drafts'] as $table) {
                $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
            }
            $wpdb->prefix = $prefix;
            update_option($option, 4);
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
