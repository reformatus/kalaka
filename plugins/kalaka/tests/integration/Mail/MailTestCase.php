<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Database\NamedLock;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Sender;
use Kalaka\Mail\Stopwatch;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Events\EventsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\TestClock;

/**
 * The base of the Mail tests: the clock at 2026-11-30 09:00:30 UTC, a fake composer, `wp_mail` answered
 * by a `pre_wp_mail` filter of the test's choosing, and helpers to queue and read deliveries.
 */
abstract class MailTestCase extends EventsTestCase
{
    protected FakeComposer $composer;

    /** @var list<string> the addresses `wp_mail` was called with */
    protected array $mailed = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->useClock(new TestClock(new \DateTimeImmutable('2026-11-30 09:00:30', new \DateTimeZone('UTC'))));
        $this->composer = new FakeComposer();
        $this->mailed = [];
        remove_all_actions(MailQueue::SEND_HOOK);
        wp_unschedule_hook(MailQueue::SEND_HOOK);
        remove_all_filters('pre_wp_mail');
        $this->mailSucceeds();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        remove_all_filters('pre_wp_mail');
        wp_unschedule_hook(MailQueue::SEND_HOOK);

        parent::tear_down();
    }

    /** Queues one delivery in a use case run from WP-CLI; returns whether it was inserted. */
    protected function queue(
        int $event,
        string $code = '12A3',
        MailKind $kind = MailKind::Action,
        string $mail = 'registered',
        string $causeKey = 'c1',
    ): bool {
        $added = $this->runner->run(
            new AdminProbe(static fn (array $input, Context $context): bool => (new MailQueue())->add(
                $context,
                new RegistrationKey($event, $code),
                $mail,
                $kind,
                new Subject('registration', $event, $causeKey)
            )),
            [],
            new CliActor(null)
        );
        self::assertIsBool($added);

        return $added;
    }

    protected function sender(?Stopwatch $stopwatch = null, bool $withComposer = true): Sender
    {
        return new Sender(
            fn () => $this->runner,
            $withComposer ? $this->composer : null,
            $stopwatch ?? new Stopwatch(),
        );
    }

    protected function mailSucceeds(): void
    {
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function (mixed $result, array $atts): bool {
            $this->mailed[] = (string) $atts['to'];

            return true;
        }, 10, 2);
    }

    /** `wp_mail` reports an error that names the recipient, as PHPMailer's do. */
    protected function mailFails(): void
    {
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function (mixed $result, array $atts): bool {
            $this->mailed[] = (string) $atts['to'];
            do_action('wp_mail_failed', new \WP_Error(
                'wp_mail_failed',
                'SMTP Error: The following recipients failed: ' . $atts['to']
            ));

            return false;
        }, 10, 2);
    }

    /** @return list<array<string, ?string>> every delivery row, in id order */
    protected function deliveries(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kalaka_deliveries ORDER BY id", ARRAY_A);
        self::assertIsArray($rows);

        return $rows;
    }

    /** @return array<string, ?string> */
    protected function delivery(int $id): array
    {
        foreach ($this->deliveries() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        self::fail('No delivery ' . $id . '.');
    }

    /** @return list<int> */
    protected function deliveryIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->deliveries());
    }

    /** @return ?array<string, ?string> the state row, or null when there is none */
    protected function stateRow(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}kalaka_mail_states WHERE id = 1", ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** Writes the state row directly, for a test's starting point. */
    protected function storeState(array $columns): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_mail_states', $columns + ['id' => 1, 'version' => 1]);
    }

    /** Takes the sender's named lock on a connection of its own, as an overlapping run would. */
    protected function holdSenderLock(): \mysqli
    {
        $connection = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $name = $connection->real_escape_string((new NamedLock(Sender::LOCK))->scoped());
        $result = $connection->query("SELECT GET_LOCK('" . $name . "', 0)");
        self::assertInstanceOf(\mysqli_result::class, $result);
        self::assertSame('1', (string) ($result->fetch_row()[0] ?? ''));

        return $connection;
    }

    /** Frees the lock `holdSenderLock()` took and closes its connection. */
    protected function freeSenderLock(\mysqli $connection): void
    {
        $name = $connection->real_escape_string((new NamedLock(Sender::LOCK))->scoped());
        $connection->query("SELECT RELEASE_LOCK('" . $name . "')");
        $connection->close();
    }

    /** @return ?int the time of the next nudge, if one is scheduled */
    protected function nextNudge(): ?int
    {
        $next = wp_next_scheduled(MailQueue::SEND_HOOK);

        return $next === false ? null : $next;
    }
}
