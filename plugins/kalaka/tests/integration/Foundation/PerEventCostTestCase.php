<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use WP_UnitTestCase;

/**
 * The per-event cost test of foundation.md, "Entities, repositories and queries": a query that
 * examines rows outside its own event fails the build. Later modules' tests `require_once` this file.
 *
 * It seeds 20 events, records the SELECTs the database receives while the query runs for one of
 * them and measures each with ANALYZE FORMAT=JSON (MariaDB's executing EXPLAIN; rows examined is
 * the sum of r_loops x r_rows over every table access). It then seeds 20 more events and measures
 * again: a query that reads only its own event's rows examines the same number however many other
 * events exist. A false failure on a small table is fixed by seeding more rows per event, never
 * by loosening the comparison.
 */
abstract class PerEventCostTestCase extends WP_UnitTestCase
{
    private const EVENTS_PER_ROUND = 20;

    /**
     * @param callable(int): void $seedEvent seeds the rows of one event
     * @param callable(int): mixed $query runs the query for one event
     */
    protected function assertPerEventCost(callable $seedEvent, callable $query): void
    {
        for ($event = 1; $event <= self::EVENTS_PER_ROUND; $event++) {
            $seedEvent($event);
        }
        $few = $this->rowsExamined($query, self::EVENTS_PER_ROUND);

        for ($event = self::EVENTS_PER_ROUND + 1; $event <= 2 * self::EVENTS_PER_ROUND; $event++) {
            $seedEvent($event);
        }
        $many = $this->rowsExamined($query, self::EVENTS_PER_ROUND);

        self::assertNotEmpty($few, 'The query ran no SELECT.');
        self::assertSame(
            array_keys($few),
            array_keys($many),
            'The number of statements or the statements themselves changed with the number of events.'
        );
        foreach ($few as $statement => $examined) {
            self::assertSame(
                $examined,
                $many[$statement],
                'The statement examined ' . $examined . ' rows with ' . self::EVENTS_PER_ROUND . ' events and '
                    . $many[$statement] . ' with ' . 2 * self::EVENTS_PER_ROUND . ': ' . $statement
            );
        }
    }

    /**
     * @param callable(int): mixed $query
     * @return array<string, float> the rows each SELECT examined, by statement text
     */
    private function rowsExamined(callable $query, int $event): array
    {
        $statements = [];
        $capture = static function (string $sql) use (&$statements): string {
            if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
                $statements[] = $sql;
            }

            return $sql;
        };
        add_filter('query', $capture);
        try {
            $query($event);
        } finally {
            remove_filter('query', $capture);
        }

        global $wpdb;
        $examined = [];
        foreach ($statements as $sql) {
            $json = $wpdb->get_var('ANALYZE FORMAT=JSON ' . $sql);
            self::assertIsString($json, 'ANALYZE FORMAT=JSON gave nothing for: ' . $sql);
            $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($plan);
            $examined[$sql . ' #' . count($examined)] = self::sumRows($plan);
        }

        return $examined;
    }

    /** @param array<mixed> $node */
    private static function sumRows(array $node): float
    {
        $sum = 0.0;
        if (isset($node['table']) && is_array($node['table']) && isset($node['table']['r_rows'])) {
            $sum += (float) ($node['table']['r_loops'] ?? 1) * (float) $node['table']['r_rows'];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $sum += self::sumRows($child);
            }
        }

        return $sum;
    }
}
