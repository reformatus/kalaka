<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\PriceLadder\TierSeatPool;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Tests\Integration\Foundation\RealTableTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * A submit races a seat-taking act for the last seat of a tier: the seat is taken under a lock on the tier's
 * row, so the submit waits for the other act's commit, then counts its seat and is refused, with nothing saved.
 * The other act runs in a child process (`tier-seat-holder.php`) as the SQL a seat taking runs, holding the tier
 * row. Two submits serialise on the event row before any tier row, so this proves the tier-row lock against an
 * act that does not take the event row, as #229's option race does. The rows are committed and removed in
 * `tear_down()`.
 */
final class TierSeatsRaceTest extends RealTableTestCase
{
    private int $event = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
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
        $wpdb->insert($wpdb->prefix . 'kalaka_price_ladder_tiers', [
            'event_id' => $this->event,
            'tier_key' => 'tamogatott',
            'position' => 0,
            'label' => 'Támogatott ár',
            'amount' => '20000',
            'currency' => 'HUF',
            'version' => 1,
            'seat_limit' => 1,
        ]);
        $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
            'event_id' => $this->event,
            'code' => '00A0',
            'version' => 1,
            'status' => 'registered',
            'secret' => 'secret',
            'created_at' => '2026-11-30 07:59:00.000000',
            'registered_at' => '2026-11-30 07:59:00.000000',
        ]);
        $wpdb->query('COMMIT');
        ObjectCache::clear();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        global $wpdb;
        $tables = [
            'kalaka_seats', 'kalaka_field_values', 'kalaka_submissions', 'kalaka_registrations',
            'kalaka_participant_fields', 'kalaka_price_ladder_tiers',
        ];
        foreach ($tables as $table) {
            $wpdb->delete($wpdb->prefix . $table, ['event_id' => $this->event]);
        }
        $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => $this->event]);
        $wpdb->query('COMMIT');
        ObjectCache::clear();

        parent::tear_down();
    }

    public function testTheSubmitThatWaitedForTheLastTierSeatIsRefusedAndNothingIsSaved(): void
    {
        global $wpdb;
        $form = new Form(999999, $this->event, FormPlacement::Registration, null, false, 1);
        $answers = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
        $pool = new TierSeatPool($this->event, 'tamogatott');
        $useCase = new SubmitRegistration(
            $form,
            1,
            new ValidatedAnswers($answers, $answers),
            1,
            [],
            false,
            [],
            (new ParticipantFields())->of($this->event),
            seatClaims: [new SeatClaim('price_tier', $pool)],
        );
        // The read above opened a transaction (the test runs without autocommit); the runner starts its own.
        $wpdb->query('COMMIT');

        $holder = $this->holdTheLastSeat('00A0');
        try {
            (new Runner(new TestClock()))->run(
                $useCase,
                ['form' => $form->id, 'token' => SubmitToken::generate()],
                new GuestActor()
            );
            self::fail('The submit should have been refused.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(
                ['price_tier' => 'Ez a lehetőség időközben betelt. Válassz másikat.'],
                $invalid->fields
            );
        }

        self::assertSame("waited\n", $this->finish($holder), 'The tier row lock was not waited for.');
        ObjectCache::clear();
        self::assertSame(1, (new Seats())->held($pool));
        self::assertSame(['00A0'], array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT code FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->event
        ))));
        self::assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_submissions WHERE event_id = %d",
            $this->event
        )));
    }

    /** @return array{resource, array<int, resource>} */
    private function holdTheLastSeat(string $code): array
    {
        global $wpdb;
        $connection = $wpdb->__get('dbh');
        self::assertInstanceOf(\mysqli::class, $connection);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/tier-seat-holder.php', $wpdb->prefix, (string) $this->event, 'tamogatott',
                $code, (string) $connection->thread_id],
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
            self::fail('tier-seat-holder.php: ' . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $holder
     * @return string what `tier-seat-holder.php` printed after "locked"
     */
    private function finish(array $holder): string
    {
        [$process, $pipes] = $holder;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the pipe to the script
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'tier-seat-holder.php: ' . $errors);

        return $output;
    }
}
