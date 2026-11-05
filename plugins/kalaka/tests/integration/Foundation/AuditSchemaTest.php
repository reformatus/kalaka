<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Module;
use WP_UnitTestCase;

require_once __DIR__ . '/IndexCheck.php';

/** The audit tables: character set, collation and indexes. */
final class AuditSchemaTest extends WP_UnitTestCase
{
    public function testTheTablesExistWithTheDeclaredCollation(): void
    {
        global $wpdb;

        self::assertSame(2, (int) get_option('kalaka_schema_foundation'));
        self::assertSame([], LoadState::current()->failures);

        $names = [$wpdb->prefix . 'kalaka_audit_entries', $wpdb->prefix . 'kalaka_audit_subjects'];
        foreach ($names as $name) {
            $collation = $wpdb->get_var($wpdb->prepare(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $name
            ));
            self::assertSame('utf8mb4_hungarian_ci', $collation, $name);

            $columns = $wpdb->get_results($wpdb->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND DATA_TYPE IN ('varchar', 'text', 'longtext')",
                $name
            ), ARRAY_A);
            self::assertNotEmpty($columns, $name);
            foreach ((array) $columns as $column) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME'], $name . '.' . $column['COLUMN_NAME']);
                self::assertSame('utf8mb4_hungarian_ci', $column['COLLATION_NAME'], $name . '.' . $column['COLUMN_NAME']);
            }
        }
    }

    public function testEveryCoreModulesIndexesAreAsDeclared(): void
    {
        global $wpdb;

        $checked = 0;
        $problems = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/*/Module.php') ?: [] as $file) {
            $class = 'Kalaka\\' . basename(dirname($file)) . '\\Module';
            $module = new $class();
            self::assertInstanceOf(Module::class, $module);

            foreach ($module->schema()->tables as $statement) {
                $statement = str_replace('{prefix}', $wpdb->prefix, $statement);
                self::assertSame(1, preg_match('/CREATE TABLE\s+(\S+)/', $statement, $match));
                $problems = [...$problems, ...IndexCheck::mismatches($match[1], $statement)];
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(2, $checked);
        self::assertSame([], $problems);
    }

    /** @return iterable<string, array{string}> */
    public static function overLongIndexes(): iterable
    {
        $table = 'CREATE TABLE {table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  long_text varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (id),
  %s long_text (long_text)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci';

        yield 'KEY' => [sprintf($table, 'KEY')];
        yield 'UNIQUE KEY' => [sprintf($table, 'UNIQUE KEY')];
    }

    /**
     * The control: the comparison must notice an index the engine shortened (`Sub_part`), dropped,
     * made a hash index, or refused to create.
     *
     * @dataProvider overLongIndexes
     */
    public function testTheComparisonNoticesAnOverLongIndex(string $statement): void
    {
        global $wpdb;

        $problems = IndexCheck::mismatchesOfNewTable($wpdb->prefix . 'kalaka_test_index', $statement);

        self::assertNotSame([], $problems);
    }
}
