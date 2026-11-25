<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Cli\MailCommand;

/** `wp kalaka mail status|pause|resume|clear-queue|clear-event|failed`: what they print, change and refuse. */
final class MailCommandTest extends MailTestCase
{
    /** @var list<array{string, list<array<string, mixed>>, list<string>}> */
    private array $printed = [];

    /** @var list<string> the questions asked */
    private array $asked = [];

    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        // The command runs the use cases through the production runner: in a test it joins the harness's transaction.
        $this->production = Runner::current();
        Runner::publish($this->runner);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);
        Runner::publish($this->production);
        parent::tear_down();
    }

    private function command(): MailCommand
    {
        $this->printed = [];
        $this->asked = [];

        return new MailCommand(
            print: function (string $format, array $rows, array $fields): void {
                $this->printed[] = [$format, $rows, $fields];
            },
            confirm: function (string $question, array $assocArgs): void {
                $this->asked[] = $question;
                if (!isset($assocArgs['yes'])) {
                    throw new \RuntimeException('declined');
                }
            }
        );
    }

    private function fails(\Closure $run): string
    {
        try {
            $run();
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $calls = array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'error'));
        self::assertCount(1, $calls);
        \WP_CLI::reset();

        return $calls[0][1][0];
    }

    /** @return list<string> */
    private function lines(): array
    {
        $lines = [];
        foreach (\WP_CLI::$calls as [$kind, $text]) {
            if ($kind === 'line' || $kind === 'success') {
                $lines[] = (string) $text;
            }
        }

        return $lines;
    }

    private function fullUser(): int
    {
        $user = self::factory()->user->create();
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);

        return $user;
    }

    public function testStatusOfARunningEngineShowsTheCountsPerEvent(): void
    {
        $event = $this->unpublished();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first] = $this->deliveryIds();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_deliveries', ['status' => 'sent'], ['id' => $first]);

        $this->command()->status([], []);

        self::assertSame(['Sending: running', 'Errors in a row: 0', 'Pending deliveries: 1'], $this->lines());
        [$format, $rows, $fields] = $this->printed[0];
        self::assertSame('table', $format);
        self::assertSame(['event', 'queued', 'sent', 'failed', 'with_error', 'stuck_sending'], $fields);
        self::assertSame([['event' => $event, 'queued' => 1, 'sent' => 1, 'failed' => 0, 'with_error' => 0,
            'stuck_sending' => 0]], $rows);
    }

    public function testStatusOfAPausePrintsCauseWhoWhenAndTheStoredError(): void
    {
        $user = self::factory()->user->create(['user_login' => 'teszt-elek']);
        $this->storeState([
            'paused_at' => '2026-11-30 08:00:00.000000',
            'cause' => 'errors',
            'paused_by' => null,
            'delivery_id' => 7,
            'delivery_event_id' => 3,
            'error' => 'SMTP down',
            'errors_in_row' => 2,
        ]);

        $this->command()->status([], ['format' => 'json']);

        self::assertSame([
            'Sending: paused',
            'Cause: errors',
            'Paused by: the system or the command line',
            'Paused since: 2026-11-30 08:00:00 UTC',
            'Delivery: 7 (event 3)',
            'Error: SMTP down',
            'Errors in a row: 2',
            'Pending deliveries: 0',
        ], $this->lines());
        self::assertSame('json', $this->printed[0][0]);

        \WP_CLI::reset();
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'manual', 'paused_by' => $user]);
        $this->command()->status([], []);
        self::assertContains('Paused by: teszt-elek (user ' . $user . ')', $this->lines());
    }

    public function testPauseThroughTheUseCaseAndAgainChangesNothing(): void
    {
        $this->command()->pause([], []);
        $this->command()->pause([], []);

        self::assertSame(['Sending is paused.', 'Sending was already paused; nothing changed.'], $this->lines());
        self::assertSame('manual', $this->stateRow()['cause'] ?? null);
        self::assertCount(2, $this->entriesOf('kalaka/pause-mail'));
    }

    public function testResumeNeedsAPersonAndAFullAccessOne(): void
    {
        $this->command()->pause([], []);
        \WP_CLI::reset();

        $message = $this->fails(fn () => $this->command()->resume([], []));
        self::assertStringContainsString('--user', $message);
        self::assertNotNull($this->stateRow()['paused_at'] ?? null);

        wp_set_current_user(self::factory()->user->create());
        $this->fails(fn () => $this->command()->resume([], []));
        self::assertNotNull($this->stateRow()['paused_at'] ?? null);

        $user = $this->fullUser();
        wp_set_current_user($user);
        $this->command()->resume([], []);
        $this->command()->resume([], []);

        self::assertSame(['Sending is resumed.', 'Sending was not paused; nothing changed.'], $this->lines());
        self::assertNull($this->stateRow()['paused_at']);
        $entries = $this->entriesOf('kalaka/resume-mail');
        self::assertSame($user, (int) $entries[count($entries) - 1]['actor_user_id']);
    }

    public function testClearQueueAsksFirstAndDeletesOnlyPendingDeliveries(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        $this->queue($event, '00K1');
        [, , $sent] = $this->deliveryIds();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_deliveries', ['status' => 'sent'], ['id' => $sent]);

        $this->fails(fn () => $this->command()->clearQueue([], []));
        self::assertSame(['Delete all 2 pending deliveries of the site?'], $this->asked);
        self::assertCount(3, $this->deliveries(), 'Declined: nothing is deleted.');

        $this->command()->clearQueue([], ['yes' => true]);

        self::assertSame(['Deleted 2 pending deliveries.'], $this->lines());
        self::assertSame([$sent], $this->deliveryIds());
        self::assertCount(1, $this->entriesOf('kalaka/clear-mail-queue'));
    }

    public function testClearQueueIsRefusedForAUserWithoutFullAccessBeforeAsking(): void
    {
        $this->queue($this->published());
        wp_set_current_user(self::factory()->user->create());

        $command = $this->command();
        $this->fails(fn () => $command->clearQueue([], ['yes' => true]));

        self::assertSame([], $this->asked);
        self::assertCount(1, $this->deliveries());
    }

    public function testClearEventDropsAHeldEventsMailAfterAskingAndRefusesAPublishedOne(): void
    {
        $held = $this->unpublished();
        $other = $this->unpublished();
        $published = $this->published();
        $this->queue($held, '00A0');
        $this->queue($other, '00A0');
        $this->queue($published, '00A0');

        $this->fails(fn () => $this->command()->clearEvent([(string) $held], []));
        self::assertSame(['Delete the 1 pending deliveries of event ' . $held . '?'], $this->asked);
        self::assertCount(3, $this->deliveries());

        $this->command()->clearEvent([(string) $held], ['yes' => true]);
        self::assertSame(['Deleted 1 pending deliveries of event ' . $held . '.'], $this->lines());
        self::assertCount(2, $this->deliveries());

        $message = $this->fails(fn () => $this->command()->clearEvent([(string) $published], ['yes' => true]));
        self::assertStringContainsString('published', $message);
        self::assertCount(2, $this->deliveries());
        self::assertSame([], $this->asked);
    }

    public function testClearEventChecksItsArguments(): void
    {
        self::assertNotSame('', $this->fails(fn () => $this->command()->clearEvent(['x'], ['yes' => true])));
        self::assertNotSame('', $this->fails(fn () => $this->command()->clearEvent(['99999'], ['yes' => true])));
        self::assertNotSame('', $this->fails(fn () => $this->command()->failed(['0'], [])));
        $message = $this->fails(fn () => $this->command()->status([], ['format' => 'x']));
        self::assertStringContainsString('format', $message);
    }

    public function testFailedListsTheCodeKindStatusAttemptAndStoredErrorButNotTheAddress(): void
    {
        $event = $this->published();
        $other = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        $this->queue($event, '00K1');
        $this->queue($event, '00G1');
        $this->queue($other, '00A0');
        [$errored, $failed, $stuck] = $this->deliveryIds();
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_deliveries';
        $wpdb->update($table, [
            'attempts' => 1,
            'claimed_at' => '2026-11-30 08:55:00.000000',
            'error' => 'The following recipients failed: teszt@example.org',
        ], ['id' => $errored]);
        $wpdb->update($table, ['status' => 'failed'], ['id' => $failed]);
        $wpdb->update($table, ['status' => 'sending', 'attempts' => 1], ['id' => $stuck]);

        $this->command()->failed([(string) $event], ['format' => 'csv']);

        [$format, $rows, $fields] = $this->printed[0];
        self::assertSame('csv', $format);
        self::assertSame(['code', 'mail', 'kind', 'status', 'attempts', 'attempted_at_utc', 'error'], $fields);
        self::assertSame(['00A0', '00B0', '00K1'], array_column($rows, 'code'));
        self::assertSame(['pending', 'failed', 'sending'], array_column($rows, 'status'));
        self::assertSame('2026-11-30 08:55:00 UTC', $rows[0]['attempted_at_utc']);
        self::assertSame('The following recipients failed: teszt@example.org', $rows[0]['error']);
        self::assertSame('', $rows[1]['error']);
        self::assertNotContains('00G1', array_column($rows, 'code'));
        self::assertArrayNotHasKey('recipient', $rows[0]);
        self::assertArrayNotHasKey('email', $rows[0]);
    }

    public function testStatusAndFailedAreForFullAccessOnly(): void
    {
        $event = $this->published();
        wp_set_current_user(self::factory()->user->create());

        $this->fails(fn () => $this->command()->status([], []));
        $existing = $this->fails(fn () => $this->command()->failed([(string) $event], []));
        // A refused user learns nothing about which events exist.
        self::assertSame($existing, $this->fails(fn () => $this->command()->failed([(string) ($event + 999)], [])));

        wp_set_current_user($this->fullUser());
        $this->command()->status([], []);
        $this->command()->failed([(string) $event], []);
        self::assertSame('table', $this->printed[0][0]);
    }
}
