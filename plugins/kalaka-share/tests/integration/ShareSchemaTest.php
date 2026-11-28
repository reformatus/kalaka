<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Share;

use Kalaka\Share\Module;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use WP_UnitTestCase;

/** The module's table. */
final class ShareSchemaTest extends WP_UnitTestCase
{
    public function testTheSettingsTableExistsWithTheEventOnly(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kalaka_share_settings';
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        self::assertSame('1', (string) get_option('kalaka_schema_' . (new Module())->id()));
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
        self::assertSame(['event_id', 'version'], $columns);
        $tables = (new Module())->schema()->tables;
        self::assertSame([], IndexCheck::mismatches($table, str_replace('{prefix}', $wpdb->prefix, $tables[0])));
    }
}
