<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Audit\Correlation;
use WP_UnitTestCase;

require_once __DIR__ . '/TestClock.php';
require_once __DIR__ . '/AdminProbe.php';

/**
 * A test that needs a table a second database connection can see: a real table, created
 * and dropped with the harness's temporary-table filters removed. Creating it commits the
 * harness transaction, so these tests make no other writes; the table is dropped after
 * parent::tear_down(), together with the audit rows a real commit left behind (they carry the
 * request's correlation ID).
 */
abstract class RealTableTestCase extends WP_UnitTestCase
{
    protected string $table;

    protected ?\mysqli $second = null;

    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);

        $this->table = $wpdb->prefix . 'kalaka_test_real';
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query(
            "CREATE TABLE {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL) ENGINE=InnoDB"
        );
    }

    public function tear_down(): void
    {
        $this->second?->close();
        $this->second = null;

        parent::tear_down();

        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query($wpdb->prepare(
            "DELETE s FROM {$wpdb->prefix}kalaka_audit_subjects s"
            . " JOIN {$wpdb->prefix}kalaka_audit_entries e ON e.id = s.entry_id WHERE e.correlation_id = %s",
            Correlation::id()
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}kalaka_audit_entries WHERE correlation_id = %s",
            Correlation::id()
        ));
        $wpdb->query('COMMIT');
    }

    /** A plain connection to the same database, outside the harness. */
    protected function secondConnection(): \mysqli
    {
        $connection = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $this->second = $connection;

        return $connection;
    }

    /** @return list<string> */
    protected function rowsSeenBy(\mysqli $connection): array
    {
        $result = $connection->query("SELECT label FROM {$this->table} ORDER BY id");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_map(static fn (array $row): string => (string) $row[0], $result->fetch_all());
    }
}
