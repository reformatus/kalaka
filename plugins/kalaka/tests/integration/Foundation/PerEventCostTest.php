<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use PHPUnit\Framework\AssertionFailedError;

/** The per-event cost harness itself: it passes an indexed query and fails one that cannot use the index. */
final class PerEventCostTest extends PerEventCostTestCase
{
    private const ROWS_PER_EVENT = 40;

    private string $table;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->table = $wpdb->prefix . 'kalaka_test_cost';
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
            "CREATE TABLE {$this->table} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL,"
            . ' label VARCHAR(50) NOT NULL, KEY event_id (event_id, id)) ENGINE=InnoDB'
        );
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");

        parent::tear_down();
    }

    private function seedEvent(int $eventId): void
    {
        global $wpdb;
        $values = [];
        for ($i = 1; $i <= self::ROWS_PER_EVENT; $i++) {
            $values[] = $wpdb->prepare('(%d, %s)', $eventId, 'row ' . $i);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $wpdb->query("INSERT INTO {$this->table} (event_id, label) VALUES " . implode(', ', $values));
    }

    public function testAQueryThatFiltersByEventFirstPasses(): void
    {
        global $wpdb;

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => $wpdb->get_results($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup; table prefix and constants only
                "SELECT * FROM {$this->table} WHERE event_id = %d",
                $event
            ))
        );
    }

    public function testAQueryThatCannotUseTheIndexFails(): void
    {
        global $wpdb;

        $this->expectException(AssertionFailedError::class);
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => $wpdb->get_results($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup; table prefix and constants only
                "SELECT * FROM {$this->table} WHERE event_id + 0 = %d",
                $event
            ))
        );
    }

    public function testAQueryWhoseStatementCountGrowsWithTheEventsFails(): void
    {
        global $wpdb;

        $this->expectException(AssertionFailedError::class);
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            function (int $event) use ($wpdb): void {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
                $events = (int) $wpdb->get_var("SELECT COUNT(DISTINCT event_id) FROM {$this->table}");
                for ($i = 0; $i < $events; $i++) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
                    $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE event_id = %d", $event));
                }
            }
        );
    }
}
