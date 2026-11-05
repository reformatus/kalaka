<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\LedgerLineRepository;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

require_once __DIR__ . '/../Foundation/PerEventCostTestCase.php';

/** AC7: the ledger's statements stay inside their event and run one statement each. */
final class LedgerCostTest extends PerEventCostTestCase
{
    private function seedEvent(int $event): void
    {
        global $wpdb;

        $values = [];
        foreach (['AAAA' => 6, 'BBBB' => 4] as $code => $lines) {
            for ($number = 1; $number <= $lines; $number++) {
                $values[] = match ($number % 3) {
                    0 => $wpdb->prepare(
                        "(%d, %s, 'charge', 'participant', 1000, 'HUF', 'fee_component', NULL, 'fixed_price', %s, NULL, NULL, '2026-11-30 08:00:00.000000')",
                        $event,
                        $code,
                        'line:' . $number
                    ),
                    1 => $wpdb->prepare(
                        "(%d, %s, 'manual_payment', 'participant', -1000, 'HUF', 'fee_component', NULL, 'fixed_price', %s, '2026-12-03', NULL, '2026-11-30 08:00:00.000000')",
                        $event,
                        $code,
                        'line:' . $number
                    ),
                    default => $wpdb->prepare(
                        "(%d, %s, 'payment', 'participant', -1000, 'HUF', 'fee_component', NULL, 'fixed_price', %s, NULL, '2026-12-01 10:00:00.000000', '2026-11-30 08:00:00.000000')",
                        $event,
                        $code,
                        'line:' . $number
                    ),
                };
            }
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_ledger_lines (event_id, code, kind, payer, amount, currency, source_type,"
            . ' source_event_id, source_key, idempotency_key, value_date, paid_at, recorded_at) VALUES ' . implode(', ', $values)
        );
    }

    public function testTheBalancesStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new Ledger())->balancesOf(new RegistrationKey($event, 'AAAA'))
        );
    }

    public function testThePaymentFactsStayInsideTheirEvent(): void
    {
        wp_timezone(); // WordPress loads its options once; that read is not the ledger's
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new Ledger())->paymentFactsOf(new RegistrationKey($event, 'AAAA'))
        );
    }

    public function testTheLookupByKeyStaysInsideItsEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event) => (new LedgerLineRepository())->findByKey(new RegistrationKey($event, 'AAAA'), 'line:2')
        );
    }

    public function testEachReadRunsOneStatement(): void
    {
        $this->seedEvent(1);
        $ledger = new Ledger();
        $registration = new RegistrationKey(1, 'AAAA');

        wp_timezone(); // WordPress loads its options once; that read is not the ledger's

        self::assertCount(1, $this->selectsDuring(fn () => $ledger->balancesOf($registration)));
        self::assertCount(1, $this->selectsDuring(fn () => $ledger->paymentFactsOf($registration)));
    }

    /** @return list<string> */
    private function selectsDuring(\Closure $work): array
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
}
