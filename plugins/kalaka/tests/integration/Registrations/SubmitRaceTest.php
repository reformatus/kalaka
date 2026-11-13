<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Tests\Integration\Foundation\RealTableTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * Two submits race for the last place: the capacity check locks the event row, so the second waits for
 * the first's commit and then counts its registration (READ COMMITTED). The first submit runs in a child
 * process (`seat-taker.php`) as the SQL a submit runs; this one is the real use case with the production
 * runner, so both transactions are real and the rows are committed and removed in `tear_down()`.
 */
final class SubmitRaceTest extends RealTableTestCase
{
    private int $event = 0;

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
            'capacity' => 1,
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
        // The event's participant fields the answers below write (#169): declared as a form save would.
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
        $wpdb->query('COMMIT');
        ObjectCache::clear();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        $tables = ['kalaka_field_values', 'kalaka_submissions', 'kalaka_registrations', 'kalaka_participant_fields'];
        foreach ($tables as $table) {
            $wpdb->delete($wpdb->prefix . $table, ['event_id' => $this->event]);
        }
        $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => $this->event]);
        $wpdb->query('COMMIT');
        ObjectCache::clear();

        parent::tear_down();
    }

    public function testTheSubmitThatWaitedForTheLastPlaceIsWaitlistedAndSetsTheLatch(): void
    {
        global $wpdb;
        $form = new Form(999999, $this->event, FormPlacement::Registration, null, false, 1);
        $answers = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
        $useCase = new SubmitRegistration($form, 1, new ValidatedAnswers($answers, $answers), 1, [], false);

        $holder = $this->takeTheLastPlace('00A0');
        $result = (new Runner(new TestClock()))->run(
            $useCase,
            ['form' => $form->id, 'token' => SubmitToken::generate()],
            new GuestActor()
        );

        self::assertSame("waited\n", $this->finish($holder), 'The event lock was not waited for.');
        self::assertInstanceOf(SubmitResult::class, $result);
        self::assertSame(RegistrationStatus::Waitlisted, $result->status);
        self::assertNotSame('00A0', $result->registration->code);
        self::assertSame('1', $wpdb->get_var($wpdb->prepare(
            "SELECT is_full FROM {$wpdb->prefix}kalaka_events WHERE id = %d",
            $this->event
        )));
        self::assertSame(
            ['registered', 'waitlisted'],
            array_map('strval', $wpdb->get_col($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d ORDER BY created_at",
                $this->event
            )))
        );
    }

    /** @return array{resource, array<int, resource>} */
    private function takeTheLastPlace(string $code): array
    {
        global $wpdb;
        $connection = $wpdb->__get('dbh');
        self::assertInstanceOf(\mysqli::class, $connection);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/seat-taker.php', $wpdb->prefix, (string) $this->event, $code,
                (string) $connection->thread_id],
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
            self::fail('seat-taker.php: ' . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $holder
     * @return string what `seat-taker.php` printed after "locked"
     */
    private function finish(array $holder): string
    {
        [$process, $pipes] = $holder;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the pipe to the script
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'seat-taker.php: ' . $errors);

        return $output;
    }
}
