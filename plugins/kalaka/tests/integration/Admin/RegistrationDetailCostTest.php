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

    public function testTheDetailStaysInsideItsEvent(): void
    {
        // `can.unconfirm` reads the clock's site time zone; the options it reads load once, in either round.
        wp_load_alloptions();
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static function (int $event): array {
                ObjectCache::clear();

                return (new RegistrationDetail())->of(new SystemActor(), $event, '00A0');
            }
        );
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, $row);
        DatabaseError::check($wpdb);
    }
}
