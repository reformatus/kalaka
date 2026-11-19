<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Pricing\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** Pricing's tables: the current fixed price per event and the manual confirmation flag. No tariff snapshot table. */
final class PricingSchemaTest extends WP_UnitTestCase
{
    public function testTheTablesAreAsDeclaredAndThereIsNoSnapshotTable(): void
    {
        global $wpdb;

        self::assertSame(2, (int) get_option('kalaka_schema_pricing'));
        self::assertSame([], LoadState::current()->failures);

        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $wpdb->prefix . 'kalaka_fixed_prices'
        ), ARRAY_A);
        self::assertIsArray($columns);
        self::assertSame(
            [
                'event_id bigint(20) unsigned ',
                'amount decimal(26,8) ',
                'currency char(3) utf8mb4_nopad_bin',
                'version int(10) unsigned ',
            ],
            array_map(
                static fn (array $c): string
                    => $c['COLUMN_NAME'] . ' ' . $c['COLUMN_TYPE'] . ' ' . $c['COLLATION_NAME'],
                $columns
            )
        );
        $flags = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $wpdb->prefix . 'kalaka_manual_confirmations'
        ), ARRAY_A);
        self::assertIsArray($flags);
        self::assertSame(
            [
                'event_id bigint(20) unsigned ',
                'code varchar(16) utf8mb4_hungarian_ci',
                'version int(10) unsigned ',
                'set_at datetime(6) ',
                'set_by bigint(20) unsigned ',
                'reason varchar(500) utf8mb4_hungarian_ci',
                'revoked_at datetime(6) ',
            ],
            array_map(
                static fn (array $c): string
                    => $c['COLUMN_NAME'] . ' ' . $c['COLUMN_TYPE'] . ' ' . $c['COLLATION_NAME'],
                $flags
            )
        );
        $snapshots = $wpdb->prefix . 'kalaka_tariff_snapshots';
        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $snapshots)));
    }

    public function testTheKeysAreAsDeclaredAndDbDeltaFindsNothingToChange(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = (new Module())->schema()->tables;
        self::assertCount(2, $tables);
        foreach ($tables as $table) {
            $statement = str_replace('{prefix}', $wpdb->prefix, $table);
            self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
            self::assertSame([], IndexCheck::mismatches($match[1], $statement));
            self::assertSame([], dbDelta([$statement], false));
        }
    }
}
