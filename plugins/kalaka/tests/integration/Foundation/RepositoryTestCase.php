<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\ObjectCache;

require_once __DIR__ . '/AuditTestCase.php';
require_once __DIR__ . '/TestItemRepository.php';
require_once __DIR__ . '/TestLogRepository.php';

/**
 * The base of the repository tests: test tables, the runner in savepoint mode, a clean object
 * cache. The actor is a CLI actor, since a system run retries a version conflict.
 */
abstract class RepositoryTestCase extends AuditTestCase
{
    protected TestItemRepository $items;

    protected TestLogRepository $logs;

    public function set_up(): void
    {
        parent::set_up();

        TestItemRepository::create('kalaka_test_items');
        TestItemRepository::create('kalaka_test_items_other');
        TestLogRepository::create('kalaka_test_logs', true);
        TestLogRepository::create('kalaka_test_log_keys', false);
        $this->items = new TestItemRepository('kalaka_test_items');
        $this->logs = new TestLogRepository('kalaka_test_logs');
        ObjectCache::clear();
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach (['items', 'items_other', 'logs', 'log_keys'] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_{$table}");
        }
        ObjectCache::clear();

        parent::tear_down();
    }

    /** Runs the work as a use case of its own and returns what it returned. */
    protected function inRun(\Closure $work): mixed
    {
        return $this->runner->run(new AdminProbe($work), [], new CliActor(null));
    }

    /** Writes a row of a test table directly, behind the repository. */
    protected function seed(string $table, string $label, int $version = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, ['label' => $label, 'version' => $version]);

        return $wpdb->insert_id;
    }

    /** @return list<string> the statements the database receives while the work runs */
    protected function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }

    /** @return array<string, ?string>|null */
    protected function rowOf(string $table, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    protected function countRows(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }
}
