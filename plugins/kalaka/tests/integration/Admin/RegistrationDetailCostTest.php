<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** The detail read's per-request cost: every statement stays inside its event. */
final class RegistrationDetailCostTest extends PerEventCostTestCase
{
    private function seedEvent(int $event): void
    {
        $this->insert('kalaka_events', [
            'id' => $event,
            'version' => 1,
            'name' => 'Tábor',
            'state' => 'published',
            'currency' => 'HUF',
            'capacity' => null,
            'is_full' => 0,
            'points' => '[]',
            'next_point_id' => 1,
        ]);
        $this->insert('kalaka_registrations', [
            'event_id' => $event,
            'code' => '00A0',
            'version' => 1,
            'status' => 'registered',
            'secret' => 'secret',
            'created_at' => '2026-11-30 08:00:00.000000',
            'registered_at' => '2026-11-30 08:00:00.000000',
        ]);
        $this->insert('kalaka_field_values', [
            'event_id' => $event,
            'code' => '00A0',
            'field_key' => 'name',
            'version' => 1,
            'value' => 'Teszt',
        ]);
        $this->insert('kalaka_participant_fields', [
            'event_id' => $event,
            'field_key' => 'name',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'Név',
            'options' => null,
            'source' => 'form',
            'retired_at' => null,
            'created_at' => '2026-11-30 08:00:00.000000',
            'updated_at' => '2026-11-30 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);
        for ($kind = 1; $kind <= 10; $kind++) {
            $this->insert('kalaka_decisions', [
                'event_id' => $event,
                'code' => '00A0',
                'kind' => 'test_' . $kind,
                'state' => 'unconfirmed',
                'effective_at' => '2026-11-30 08:00:00.000000',
                'recorded_at' => '2026-11-30 08:00:00.000000',
                'cause_type' => 'test_record',
                'cause_event_id' => $event,
                'cause_key' => '1',
                'actor_type' => 'system',
            ]);
        }
        $this->insert('kalaka_submissions', [
            'event_id' => $event,
            'code' => '00A0',
            'number' => 1,
            'form_id' => 0,
            'form_version' => 0,
            'answers' => '{}',
            'submitted_at' => '2026-11-30 08:00:00.000000',
        ]);

        $lines = [];
        for ($number = 1; $number <= 10; $number++) {
            $lines[] = ['charge', '60000', 'charge:' . $number, null, null, null];
            $lines[] = [
                'manual_payment',
                '-35000',
                'manual:p' . $number,
                '2026-12-02 23:00:00.000000',
                'bank_transfer',
                'Teszt',
            ];
            $lines[] = [
                'manual_refund',
                '5000',
                'manual:r' . $number,
                '2026-12-03 23:00:00.000000',
                'cash',
                'Teszt',
            ];
        }
        foreach ($lines as [$kind, $amount, $key, $valueDate, $method, $note]) {
            $this->insert('kalaka_ledger_lines', [
                'event_id' => $event,
                'code' => '00A0',
                'kind' => $kind,
                'payer' => 'participant',
                'amount' => $amount,
                'currency' => 'HUF',
                'source_type' => 'system',
                'source_event_id' => null,
                'source_key' => 'system',
                'idempotency_key' => $key,
                'value_date' => $valueDate,
                'paid_at' => null,
                'recorded_at' => '2026-11-30 08:00:00.000000',
                'method' => $method,
                'correction' => 0,
            ]);
            if ($note !== null) {
                global $wpdb;
                $this->insert('kalaka_payment_notes', [
                    'event_id' => $event,
                    'line_id' => (int) $wpdb->insert_id,
                    'code' => '00A0',
                    'version' => 1,
                    'note' => $note,
                ]);
            }
        }
    }

    /** Several manual fees, each with its own owed line, cost no statement more: the names come in one read. */
    private function seedManualFees(int $event, int $count = 5): void
    {
        global $wpdb;
        for ($number = 1; $number <= $count; $number++) {
            $this->insert('kalaka_manual_fees', [
                'event_id' => $event,
                'code' => '00A0',
                'type' => $number % 2 === 0 ? 'discount' : 'charge',
                'payer' => 'participant',
                'amount' => '100',
                'currency' => 'HUF',
                'title' => 'Teszt ' . $number,
                'idempotency_key' => 'manual_fee:f' . $number,
                'recorded_by' => null,
                'recorded_at' => '2026-11-30 08:00:00.000000',
                'version' => 1,
            ]);
            $this->insert('kalaka_ledger_lines', [
                'event_id' => $event,
                'code' => '00A0',
                'kind' => 'charge',
                'payer' => 'participant',
                'amount' => '100',
                'currency' => 'HUF',
                'source_type' => 'fee_component',
                'source_event_id' => null,
                'source_key' => 'manual:' . $wpdb->insert_id,
                'idempotency_key' => 'charge:manual:' . $number . ':participant:1',
                'value_date' => null,
                'paid_at' => null,
                'recorded_at' => '2026-11-30 08:00:00.000000',
                'method' => null,
                'correction' => 0,
            ]);
        }
    }

    public function testTheDetailStaysInsideItsEvent(): void
    {
        // `can.unconfirm` reads the clock's site time zone; the options it reads load once, in either round.
        wp_load_alloptions();
        $this->assertPerEventCost(
            function (int $event): void {
                $this->seedEvent($event);
                $this->seedManualFees($event);
            },
            static function (int $event): array {
                ObjectCache::clear();

                return (new RegistrationDetail())->of(new SystemActor(), $event, '00A0');
            }
        );
    }

    public function testSeveralManualFeesCostNoStatementMore(): void
    {
        $this->seedEvent(1);
        $this->seedManualFees(1, 1);
        $this->seedEvent(2);
        $this->seedManualFees(2, 5);

        $this->selectCount(1); // warms the request-wide caches (options), which belong to no fee
        self::assertSame($this->selectCount(1), $this->selectCount(2));
    }

    /** The number of SELECTs the detail read sends for one event. */
    private function selectCount(int $event): int
    {
        $count = 0;
        $capture = static function (string $sql) use (&$count): string {
            if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
                $count++;
            }

            return $sql;
        };
        ObjectCache::clear();
        add_filter('query', $capture);
        (new RegistrationDetail())->of(new SystemActor(), $event, '00A0');
        remove_filter('query', $capture);

        return $count;
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, $row);
        DatabaseError::check($wpdb);
    }
}
