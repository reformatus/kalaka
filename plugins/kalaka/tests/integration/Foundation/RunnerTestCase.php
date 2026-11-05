<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\UseCase\Runner;
use WP_UnitTestCase;

require_once __DIR__ . '/TestClock.php';
require_once __DIR__ . '/AdminProbe.php';
require_once __DIR__ . '/PortalProbe.php';
require_once __DIR__ . '/UnsidedUseCase.php';
require_once __DIR__ . '/BothSidesUseCase.php';

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

    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->table = $wpdb->prefix . 'kalaka_test_rows';
        $wpdb->query(
            "CREATE TABLE {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL) ENGINE=InnoDB"
        );

        $this->clock = new TestClock();
        $this->runner = new Runner($this->clock, true);
    }

    public function tear_down(): void
    {
        global $wpdb;
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
        $log = (string) file_get_contents($file);
        unlink($file);

        return $log;
    }
}
