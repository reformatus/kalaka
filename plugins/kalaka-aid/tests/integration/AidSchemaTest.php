<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Aid;

use Kalaka\Aid\Module;

/** The module's table. */
final class AidSchemaTest extends AidTestCase
{
    public function testTheSettingsTableExistsWithTheEventAndTheLabelOnly(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kalaka_aid_settings';
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        self::assertSame('1', (string) get_option('kalaka_schema_' . (new Module())->id()));
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
        self::assertSame(['event_id', 'label', 'version'], $columns);
    }
}
