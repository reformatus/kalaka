<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Pricing\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** Pricing's one table: the current fixed price per event. No tariff snapshot table. */
final class PricingSchemaTest extends WP_UnitTestCase
{
    public function testTheFixedPriceTableIsAsDeclaredAndThereIsNoSnapshotTable(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_pricing'));
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
        $snapshots = $wpdb->prefix . 'kalaka_tariff_snapshots';
        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $snapshots)));
    }

    public function testTheKeysAreAsDeclaredAndDbDeltaFindsNothingToChange(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = (new Module())->schema()->tables;
        self::assertCount(1, $tables);
        $statement = str_replace('{prefix}', $wpdb->prefix, $tables[0]);
        self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
        self::assertSame([], IndexCheck::mismatches($match[1], $statement));
        self::assertSame([], dbDelta([$statement], false));
    }
}
