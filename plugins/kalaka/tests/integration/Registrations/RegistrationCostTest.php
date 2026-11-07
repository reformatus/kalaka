<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Registrations\Module;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\ReferenceCodeQuery;
use Kalaka\Registrations\RegistrationList;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** AC12: the per-event statements stay inside their event, and the site-wide one stays a loose index scan. */
final class RegistrationCostTest extends PerEventCostTestCase
{
    private const CODES_PER_EVENT = 100;

    private bool $shadowed = false;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        if ($this->shadowed) {
            // The harness turns this into DROP TEMPORARY TABLE, so the real table stays.
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_registrations");
        }

        parent::tear_down();
    }

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

    public function testTheCodesOfAnEventStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new ReferenceCodeQuery())->ofEvent($event)
        );
    }

    public function testTheSiteWideStatementExaminesAtMostOneRowMoreThanTheDistinctCodes(): void
    {
        global $wpdb;
        // A table of the same name that only this connection sees (the harness makes every CREATE TABLE
        // temporary), so its index statistics can be refreshed without committing the harness's transaction.
        $this->shadowed = true;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; table prefix and constants only
        self::assertNotFalse($wpdb->query(str_replace('{prefix}', $wpdb->prefix, (new Module())->schema()->tables[0])));
        for ($event = 1; $event <= 40; $event++) {
            $this->seedEvent($event);
        }
        $wpdb->query("ANALYZE TABLE {$wpdb->prefix}kalaka_registrations");

        $statements = $this->statementsOf(function (): void {
            self::assertCount(self::CODES_PER_EVENT, (new ReferenceCodeQuery())->onTheSite());
        });
        self::assertCount(1, $statements);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test setup; table prefix and constants only
        $json = $wpdb->get_var('ANALYZE FORMAT=JSON ' . $statements[0]);
        self::assertIsString($json);
        $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        $examined = self::rowsExamined($plan);

        self::assertLessThanOrEqual(self::CODES_PER_EVENT + 1, $examined, 'The plan was: ' . $json);
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
