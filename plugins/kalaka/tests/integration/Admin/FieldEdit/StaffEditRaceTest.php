<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\PreparedEdit;
use Kalaka\Assignments\OptionSeatPool;
use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\FieldCorrections;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Tests\Integration\Foundation\RealTableTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * Two staff edits race for the last seat of an option: the seat is taken under a lock on the option row, so the edit
 * waits for the other act's commit, then counts its own seat and goes over the limit. Both succeed and the count is
 * exact. The other act (the kind staff placing is, `PlaceRegistration`) runs in a child process (`seat-holder.php`)
 * as the SQL a seat taking runs. The rows are committed and removed in `tear_down()`.
 */
final class StaffEditRaceTest extends RealTableTestCase
{
    private int $event = 0;

    private int $set = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_events', [
            'version' => 1,
            'name' => 'Teszt tábor',
            'state' => 'published',
            'currency' => 'HUF',
            'capacity' => 10,
            'is_full' => 0,
            'registration_opens_at' => '2026-11-29 23:00:00.000000',
            'registration_closes_at' => '2026-12-15 23:00:00.000000',
            'starts_at' => '2027-07-04 22:00:00.000000',
            'ends_at' => '2027-07-11 22:00:00.000000',
            'points' => '[]',
            'next_point_id' => 1,
        ]);
        $this->event = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->event, (string) $wpdb->last_error);
        foreach (['name' => 'kalaka/name-field', 'email' => 'kalaka/email-field'] as $key => $type) {
            $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
                'event_id' => $this->event,
                'field_key' => $key,
                'version' => 1,
                'type' => $type,
                'title' => $key,
                'source' => 'form',
                'created_at' => '2026-11-30 07:00:00.000000',
                'updated_at' => '2026-11-30 07:00:00.000000',
            ]);
        }
        $wpdb->insert($wpdb->prefix . 'kalaka_assignment_sets', [
            'event_id' => $this->event,
            'version' => 1,
            'name' => 'Modulok',
            'required' => 0,
            'mode' => 'request',
            'published' => 0,
        ]);
        $this->set = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'kalaka_assignment_options', [
            'set_id' => $this->set,
            'option_key' => 'modul_a',
            'event_id' => $this->event,
            'version' => 1,
            'label' => 'Modul A',
            'capacity' => 1,
            'leaders' => '',
            'place' => '',
        ]);
        // The registrations: one holds the last seat (taken by the other act), the other is edited.
        foreach (['00A0', '00A2'] as $code) {
            $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
                'event_id' => $this->event,
                'code' => $code,
                'version' => 1,
                'status' => 'registered',
                'secret' => 'secret',
                'created_at' => '2026-11-30 07:59:00.000000',
                'registered_at' => '2026-11-30 07:59:00.000000',
            ]);
        }
        $wpdb->query('COMMIT');
        ObjectCache::clear();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        $tables = [
            'kalaka_seats', 'kalaka_field_values', 'kalaka_submissions', 'kalaka_registrations',
            'kalaka_participant_fields', 'kalaka_assignment_options',
        ];
        foreach ($tables as $table) {
            $wpdb->delete($wpdb->prefix . $table, ['event_id' => $this->event]);
        }
        $wpdb->delete($wpdb->prefix . 'kalaka_assignment_sets', ['id' => $this->set]);
        $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => $this->event]);
        $wpdb->query('COMMIT');
        ObjectCache::clear();

        parent::tear_down();
    }

    public function testTheEditThatWaitedForTheLastSeatGoesOverTheLimitAndTheCountIsExact(): void
    {
        global $wpdb;
        $pool = new OptionSeatPool($this->event, $this->set, 'modul_a');
        $source = new class ($pool) implements SeatClaimSource {
            public function __construct(private readonly OptionSeatPool $pool)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                return ($answers->stored()['tier'] ?? '') === 'b' ? [new SeatClaim('tier', $this->pool)] : [];
            }

            public function pools(Event $event): array
            {
                return [];
            }
        };
        $useCase = new EditFieldValue(
            new PreparedEdit(
                $this->event,
                new ParticipantField('tier', 'kalaka/choice-field', 'tier', [], 'form', false),
                'b'
            ),
            new FieldCorrections([$source]),
        );
        // The read above opened a transaction (the test runs without autocommit); the runner starts its own.
        $wpdb->query('COMMIT');

        $holder = $this->holdTheLastSeat('00A0');
        $result = (new Runner(new TestClock()))->run(
            $useCase,
            ['event_id' => $this->event, 'code' => '00A2', 'field' => 'tier', 'value' => 'b', 'reason' => 'Elírás'],
            new CliActor(null)
        );

        self::assertSame("waited\n", $this->finish($holder), 'The option row lock was not waited for.');
        self::assertSame(['changed' => true, 'over_limit' => ['tier']], $result);
        ObjectCache::clear();
        $seats = new Seats();
        self::assertSame(2, $seats->held($pool));
        self::assertSame(1, $seats->over($pool));
        self::assertTrue($seats->holds($pool, new RegistrationKey($this->event, '00A2')));
    }

    /** @return array{resource, array<int, resource>} */
    private function holdTheLastSeat(string $code): array
    {
        global $wpdb;
        $connection = $wpdb->__get('dbh');
        self::assertInstanceOf(\mysqli::class, $connection);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Registrations/seat-holder.php', $wpdb->prefix, (string) $this->event,
                (string) $this->set, 'modul_a', $code, (string) $connection->thread_id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'KALAKA_DB_HOST' => DB_HOST,
                'KALAKA_DB_USER' => DB_USER,
                'KALAKA_DB_PASSWORD' => DB_PASSWORD,
                'KALAKA_DB_NAME' => DB_NAME,
            ]
        );
        self::assertIsResource($process);
        $line = fgets($pipes[1]);
        if ($line !== "locked\n") {
            self::fail('seat-holder.php: ' . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $holder
     * @return string what `seat-holder.php` printed after "locked"
     */
    private function finish(array $holder): string
    {
        [$process, $pipes] = $holder;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the pipe to the script
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'seat-holder.php: ' . $errors);

        return $output;
    }
}
