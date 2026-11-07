<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\UseCase\Runner;
use WP_UnitTestCase;

/**
 * The base of the runner tests: a runner on the harness's open transaction (D14) and a
 * test table. WP_UnitTestCase turns CREATE TABLE into a temporary table and rolls the
 * harness transaction back after each test.
 */
abstract class RunnerTestCase extends WP_UnitTestCase
{
    protected TestClock $clock;

    protected Runner $runner;

    protected string $table;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->table = $wpdb->prefix . 'kalaka_test_rows';
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
            "CREATE TABLE {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL) ENGINE=InnoDB"
        );

        $this->clock = new TestClock();
        $this->runner = new Runner($this->clock, true);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");

        parent::tear_down();
    }

    protected function insertRow(string $label): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['label' => $label]);
        DatabaseError::check($wpdb);
    }

    /** @return list<string> */
    protected function rows(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        return array_map('strval', $wpdb->get_col("SELECT label FROM {$this->table} ORDER BY id"));
    }

    /** Captures what PHP writes to its error log while the callback runs. */
    protected function capturedErrorLog(callable $callback): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previous = ini_set('error_log', $file);
        try {
            $callback();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink($file);

        return $log;
    }
}
