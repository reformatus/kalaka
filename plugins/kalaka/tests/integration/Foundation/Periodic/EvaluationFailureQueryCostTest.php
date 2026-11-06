<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

require_once __DIR__ . '/../PerEventCostTestCase.php';

/** The per-event cost test of the query that reads the flags of the given events. */
final class EvaluationFailureQueryCostTest extends PerEventCostTestCase
{
    private const FLAGS_PER_EVENT = 30;

    public function testTheQueryExaminesOnlyTheGivenEventsRows(): void
    {
        $this->assertPerEventCost(
            function (int $event): void {
                global $wpdb;
                $values = [];
                for ($i = 0; $i < self::FLAGS_PER_EVENT; $i++) {
                    $values[] = $wpdb->prepare(
                        "(%d, %s, 1, 'decisions/test', '{}', '2026-11-29 08:00:00.000000')",
                        $event,
                        sprintf('00A%02d', $i)
                    );
                }
                $wpdb->query(
                    "INSERT INTO {$wpdb->prefix}kalaka_evaluation_failures (event_id, code, version, rule, error,"
                    . ' failed_at) VALUES ' . implode(', ', $values)
                );
            },
            static fn (int $event): array => (new EvaluationFailureQuery())->flaggedIn([$event])
        );
    }
}
