<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Decision\DecisionQuery;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** The per-event cost test of the query that reads one registration's decisions. */
final class DecisionQueryCostTest extends PerEventCostTestCase
{
    private const CODES = ['00A0', '00A1', '00A2', '00A3'];

    private const ROWS_PER_CODE = 20;

    public function testTheQueryExaminesOnlyItsOwnEventsRows(): void
    {
        $this->assertPerEventCost(
            function (int $event): void {
                global $wpdb;
                $values = [];
                foreach (self::CODES as $code) {
                    for ($i = 0; $i < self::ROWS_PER_CODE; $i++) {
                        $values[] = $wpdb->prepare(
                            "(%d, %s, %s, %s, '2026-11-29 08:00:00.000000', '2026-11-29 08:00:00.000000',"
                            . " 'test_record', 12, '1', 'system')",
                            $event,
                            $code,
                            'kind_' . ($i % 3),
                            'state_' . $i
                        );
                    }
                }
                $wpdb->query(
                    "INSERT INTO {$wpdb->prefix}kalaka_decisions (event_id, code, kind, state, effective_at,"
                    . ' recorded_at, cause_type, cause_event_id, cause_key, actor_type) VALUES '
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; statement built from prefix and constants
                    . implode(', ', $values)
                );
            },
            static fn (int $event): array => (new DecisionQuery())->currentOf(new RegistrationKey($event, '00A1'))
        );
    }
}
