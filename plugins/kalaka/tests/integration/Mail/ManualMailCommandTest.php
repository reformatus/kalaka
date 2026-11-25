<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Mail\Cli\ManualMailBatchesCommand;
use Kalaka\Mail\Cli\ManualMailCommand;

/** `wp kalaka mail send|batches`: what they print, queue and refuse. */
final class ManualMailCommandTest extends MailBodyTestCase
{
    /** @var list<array{string, list<array<string, mixed>>, list<string>}> */
    private array $printed = [];

    /** @var list<string> the questions asked */
    private array $asked = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function command(): ManualMailCommand
    {
        $this->printed = [];
        $this->asked = [];

        return new ManualMailCommand(
            confirm: function (string $question, array $assocArgs): void {
                $this->asked[] = $question;
                if (!isset($assocArgs['yes'])) {
                    throw new \RuntimeException('declined');
                }
            }
        );
    }

    private function batchesCommand(): ManualMailBatchesCommand
    {
        $this->printed = [];

        return new ManualMailBatchesCommand(
            print: function (string $format, array $rows, array $fields): void {
                $this->printed[] = [$format, $rows, $fields];
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
    private function lines(string ...$kinds): array
    {
        $lines = [];
        foreach (\WP_CLI::$calls as [$kind, $text]) {
            if (in_array($kind, $kinds === [] ? ['line', 'success'] : $kinds, true)) {
                $lines[] = (string) $text;
            }
        }

        return $lines;
    }

    /** @return array{int, int} the event and a body of it, with registrations 00A0 and 00A2 */
    private function ready(): array
    {
        $event = $this->published();
        $this->registration($event, '00A0');
        $this->registration($event, '00A2');

        return [$event, $this->document($event, $this->paragraph('Szia'))];
    }

    public function testADryRunShowsTheCountsAndNamesTheUnknownCodesAndWritesNothing(): void
    {
        $event = $this->published();
        $this->registration($event, '00A0');
        $this->registration($event, '00A2', status: 'withdrawn');
        $body = $this->document($event, $this->paragraph('Szia'));

        $this->command()->__invoke([(string) $event], [
            'body' => (string) $body, 'codes' => '00A0,00A2,00A5', 'dry-run' => true,
        ]);

        self::assertSame([
            '1 to queue, 1 skipped (withdrawn/removed): 00A2 (withdrawn), unknown: 00A5',
            'Dry run: nothing was queued.',
        ], $this->lines());
        self::assertSame([], $this->asked);
        self::assertSame([], $this->deliveries());
    }

    public function testItAsksThenQueuesOneBatchAndPrintsItsId(): void
    {
        [$event, $body] = $this->ready();

        $this->fails(fn () => $this->command()->__invoke(
            [(string) $event],
            ['body' => (string) $body, 'codes' => '00A0,00A2']
        ));
        self::assertSame(['Queue this mail for 2 registrations of event ' . $event . '?'], $this->asked);
        self::assertSame([], $this->deliveries());

        $this->command()->__invoke(
            [(string) $event],
            ['body' => (string) $body, 'codes' => '00A0,00A2,00A0', 'yes' => true]
        );

        $lines = $this->lines();
        self::assertSame('2 to queue, 0 skipped (withdrawn/removed), unknown: none', $lines[0]);
        self::assertStringStartsWith('Batch ', $lines[1]);
        self::assertStringEndsWith(' queued. Queued: 2, skipped: 0.', $lines[1]);
        self::assertCount(2, $this->deliveries());
        self::assertSame(['manual', 'manual'], array_column($this->deliveries(), 'kind'));
    }

    public function testCodesComeFromAFileOneToALineOrCommaSeparated(): void
    {
        [$event, $body] = $this->ready();
        $path = wp_tempnam('codes');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary test file
        file_put_contents($path, "00A0\n00A2,\n");
        try {
            $this->command()->__invoke(
                [(string) $event],
                ['body' => (string) $body, 'codes-file' => $path, 'yes' => true]
            );
        } finally {
            wp_delete_file($path);
        }

        self::assertCount(2, $this->deliveries());
    }

    public function testNoLayoutAndAKeyAreKeptOnTheBatchAndARerunWithTheKeyQueuesNothing(): void
    {
        [$event, $body] = $this->ready();
        $args = ['body' => (string) $body, 'codes' => '00A0', 'layout' => false, 'key' => 'rerun-key-1', 'yes' => true];

        $this->command()->__invoke([(string) $event], $args);
        \WP_CLI::reset();
        $this->command()->__invoke([(string) $event], $args);

        self::assertStringContainsString('same key was used before', $this->lines()[1]);
        self::assertCount(1, $this->deliveries());
        $this->batchesCommand()->__invoke([(string) $event], []);
        self::assertSame('no', $this->printed[0][1][0]['layout']);
    }

    public function testAnUnknownCodeRefusesBeforeAskingAndABadCodeIsNamed(): void
    {
        [$event, $body] = $this->ready();

        $message = $this->fails(fn () => $this->command()->__invoke([(string) $event], [
            'body' => (string) $body, 'codes' => '00A0,00A5', 'yes' => true,
        ]));
        self::assertStringContainsString('no registration of the event', $message);
        self::assertSame([], $this->asked);
        self::assertSame([], $this->deliveries());

        $message = $this->fails(fn () => $this->command()->__invoke([(string) $event], [
            'body' => (string) $body, 'codes' => '00A0,xx', 'yes' => true,
        ]));
        self::assertStringContainsString('Not a registration code: xx', $message);
    }

    public function testItNeedsTheCodesTheBodyAndAnExistingEvent(): void
    {
        [$event, $body] = $this->ready();

        self::assertStringContainsString('--codes', $this->fails(
            fn () => $this->command()->__invoke([(string) $event], ['body' => (string) $body])
        ));
        self::assertStringContainsString('--body', $this->fails(
            fn () => $this->command()->__invoke([(string) $event], ['codes' => '00A0'])
        ));
        $this->fails(fn () => $this->command()->__invoke(['0'], ['body' => (string) $body, 'codes' => '00A0']));
    }

    public function testAnUnpublishedBodyIsRefusedWithTheUseCasesText(): void
    {
        [$event] = $this->ready();
        $draft = $this->document($event, $this->paragraph('Szia'));
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);

        $message = $this->fails(fn () => $this->command()->__invoke([(string) $event], [
            'body' => (string) $draft, 'codes' => '00A0', 'yes' => true,
        ]));

        self::assertStringContainsString('Előbb tedd közzé a levélsablont.', $message);
    }

    public function testAPausedSiteAndAnUnpublishedEventPrintWarningsNotErrors(): void
    {
        $event = $this->unpublished();
        $this->registration($event, '00A0');
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->storeState(['paused_at' => '2026-11-30 08:00:00.000000', 'cause' => 'manual', 'errors_in_row' => 0]);

        $this->command()->__invoke([(string) $event], ['body' => (string) $body, 'codes' => '00A0', 'yes' => true]);

        $warnings = $this->lines('warning');
        self::assertCount(2, $warnings);
        self::assertStringContainsString('paused', $warnings[0]);
        self::assertStringContainsString('not published', $warnings[1]);
        self::assertCount(1, $this->deliveries());
    }

    public function testAUserWithoutAGrantIsRefusedBeforeAnyCodeIsShown(): void
    {
        [$event, $body] = $this->ready();
        wp_set_current_user(self::factory()->user->create());

        $this->fails(fn () => $this->command()->__invoke([(string) $event], [
            'body' => (string) $body, 'codes' => '00A0,00A5', 'dry-run' => true,
        ]));

        self::assertSame([], $this->lines());
        self::assertSame([], $this->deliveries());
    }

    public function testAGrantHoldingUserSendsInsideTheViewAndIsRefusedOutsideIt(): void
    {
        [$event, $body] = $this->ready();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_registrations', ['status' => 'waitlisted'], ['code' => '00A2']);
        ActionCatalogue::record(new ActionCatalogue(['kalaka_manual_mail']));
        $user = self::factory()->user->create(['role' => 'editor']);
        $view = $this->exec(new \Kalaka\Admin\View\SaveView(), [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => [['column' => 'status', 'in' => ['registered']]],
            'columns' => [['column' => 'code', 'write' => false]],
        ]);
        $this->exec(new \Kalaka\Admin\Grant\SaveGrant(), [
            'event_id' => $event, 'user_id' => $user, 'view_id' => $view, 'bound_value' => null,
            'actions' => ['kalaka_manual_mail'],
        ]);
        wp_set_current_user($user);

        $this->command()->__invoke([(string) $event], ['body' => (string) $body, 'codes' => '00A0', 'yes' => true]);
        self::assertCount(1, $this->deliveries());
        \WP_CLI::reset();

        $this->fails(fn () => $this->command()->__invoke([(string) $event], [
            'body' => (string) $body, 'codes' => '00A0,00A2', 'yes' => true,
        ]));
        self::assertCount(1, $this->deliveries());
        self::assertSame($user, (int) $this->entriesOf('kalaka/send-manual-mail')[0]['actor_user_id']);
    }

    public function testBatchesListsTheEventsBatchesAndOneBatchsMembers(): void
    {
        $event = $this->published();
        $this->registration($event, '00A0');
        $this->registration($event, '00A2', status: 'removed');
        $body = $this->document($event, $this->paragraph('Szia'), 'Tájékoztató');
        $user = $this->fullUser();
        wp_set_current_user($user);
        $this->command()->__invoke(
            [(string) $event],
            ['body' => (string) $body, 'codes' => '00A0,00A2', 'yes' => true]
        );

        $this->batchesCommand()->__invoke([(string) $event], ['format' => 'json']);

        [$format, $rows, $fields] = $this->printed[0];
        self::assertSame('json', $format);
        self::assertSame(['batch', 'created_at_utc', 'by', 'body', 'layout', 'queued', 'skipped'], $fields);
        self::assertSame([1, 1], [$rows[0]['queued'], $rows[0]['skipped']]);
        self::assertSame($body . ' Tájékoztató', $rows[0]['body']);
        self::assertStringContainsString('(user ' . $user . ')', $rows[0]['by']);

        $this->batchesCommand()->__invoke([(string) $event, (string) $rows[0]['batch']], []);
        self::assertSame(
            [['code' => '00A0', 'outcome' => 'queued'], ['code' => '00A2', 'outcome' => 'skipped_removed']],
            $this->printed[0][1]
        );
    }

    public function testBatchesIsForFullAccessAndNamesNoOtherEventsBatch(): void
    {
        [$event, $body] = $this->ready();
        wp_set_current_user(self::factory()->user->create());
        $existing = $this->fails(fn () => $this->batchesCommand()->__invoke([(string) $event], []));
        // A refused user learns nothing about which events exist.
        $missing = $this->fails(fn () => $this->batchesCommand()->__invoke([(string) ($event + 999)], []));
        self::assertSame($existing, $missing);

        wp_set_current_user(0);
        $other = $this->published();
        $this->fails(fn () => $this->batchesCommand()->__invoke([(string) $other, '1'], []));
        self::assertNotSame(0, $body);
    }

    private function fullUser(): int
    {
        $user = self::factory()->user->create();
        get_userdata($user)->add_cap(AdminAccess::FULL_ACCESS);

        return $user;
    }
}
