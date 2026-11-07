<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\ReferenceCodeGenerator;
use Kalaka\Registrations\ReferenceCodeQuery;
use Kalaka\Registrations\RegistrationList;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** AC12: the per-event statements stay inside their event, and the site-wide one reads only the codes asked for. */
final class RegistrationCostTest extends PerEventCostTestCase
{
    private const CODES_PER_EVENT = 100;

    private function seedEvent(int $event): void
    {
        global $wpdb;

        $values = [];
        foreach (array_slice(ReferenceCode::fourCharacterCodes(), 0, self::CODES_PER_EVENT) as $code) {
            $values[] = $wpdb->prepare(
                "(%d, %s, 1, 'registered', 'secret', '2026-11-30 08:00:00.000000')",
                $event,
                $code
            );
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_registrations "
            . "(event_id, code, version, status, secret, created_at) VALUES "
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; table prefix and constants only
            . implode(', ', $values)
        );
    }

    public function testTheRegistrationListStaysInsideItsEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new RegistrationList())->forEvent($event)
        );
    }

    public function testTheRegistrationListRunsOneStatement(): void
    {
        $this->seedEvent(1);
        $statements = $this->statementsOf(fn () => (new RegistrationList())->forEvent(1));

        self::assertCount(1, $statements);
    }

    public function testTheCandidatesAnEventUsesStayInsideTheirEvent(): void
    {
        $candidates = self::candidates();
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new ReferenceCodeQuery())->usedByEvent($event, $candidates)
        );
    }

    public function testTheCodesOfOneLengthOfAnEventStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new ReferenceCodeQuery())->ofEventWithLength($event, ReferenceCode::MIN_LENGTH)
        );
    }

    public function testTheSiteWideStatementReadsOnlyTheIndexEntriesOfTheCodesAskedFor(): void
    {
        global $wpdb;
        $events = 20;
        for ($event = 1; $event <= $events; $event++) {
            $this->seedEvent($event);
        }

        $statements = $this->statementsOf(function (): void {
            // Half of the candidates are used, by every event; the other half by none.
            self::assertCount(
                ReferenceCodeGenerator::BATCH / 2,
                (new ReferenceCodeQuery())->usedOnTheSite(self::candidates())
            );
        });
        self::assertCount(1, $statements);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; table prefix and constants only
        $json = $wpdb->get_var('ANALYZE FORMAT=JSON ' . $statements[0]);
        self::assertIsString($json);
        $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);

        // Point lookups on `KEY code` (a range of the index, or a loose index scan over it): never more
        // rows than hold the codes asked for, whatever else the site holds.
        // 10.3 puts the table under `query_block`; 11.8 under `query_block.nested_loop`.
        $table = $plan['query_block']['table'] ?? $plan['query_block']['nested_loop'][0]['table'] ?? [];
        self::assertSame('code', $table['key'] ?? null, 'The plan was: ' . $json);
        self::assertLessThanOrEqual(
            ReferenceCodeGenerator::BATCH / 2 * $events,
            self::rowsExamined($plan),
            'The plan was: ' . $json
        );
    }

    public function testACodeCostsTwoStatementsWhileTheBatchHoldsOneTheEventDoesNotUse(): void
    {
        $this->seedEvent(1);
        $this->seedEvent(2);

        $statements = $this->statementsOf(fn () => (new ReferenceCodeGenerator())->generate(1));

        self::assertCount(2, $statements);
        self::assertStringContainsString('WHERE event_id = 1 AND code IN (', $statements[0]);
        self::assertStringContainsString('SELECT DISTINCT code', $statements[1]);
    }

    /** @return list<string> `BATCH` codes: the first half used by every seeded event, the second half by none */
    private static function candidates(): array
    {
        $all = ReferenceCode::fourCharacterCodes();

        return [
            ...array_slice($all, 0, ReferenceCodeGenerator::BATCH / 2),
            ...array_slice($all, 4000, ReferenceCodeGenerator::BATCH / 2),
        ];
    }

    /** @return list<string> the SELECTs the database receives while the work runs */
    private function statementsOf(\Closure $work): array
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
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $statements;
    }

    /** @param array<mixed> $node */
    private static function rowsExamined(array $node): float
    {
        $sum = 0.0;
        if (isset($node['table']) && is_array($node['table']) && isset($node['table']['r_rows'])) {
            $sum += (float) ($node['table']['r_loops'] ?? 1) * (float) $node['table']['r_rows'];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $sum += self::rowsExamined($child);
            }
        }

        return $sum;
    }
}
