<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\Cli\EventCommand;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Forms\BrokenForms;
use Kalaka\Mail\Body\AccountLinkRule;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/** `wp kalaka event create|update|show|list|publish|unpublish|archive` (go-live G1, G2, G8). */
final class EventCommandTest extends FormsTestCase
{
    private const INVALID = 'Hibás vagy hiányzó adat.';

    private const REFUSED = 'A művelet nem hajtható végre.';

    private const CONFLICT = 'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.';

    /** @var list<string> */
    private array $files = [];

    /** @var list<array{string, list<array<string, string>>, list<string>}> */
    private array $printed = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        $this->printed = [];
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        array_map('wp_delete_file', $this->files);
        parent::tear_down();
    }

    private function command(): EventCommand
    {
        return new EventCommand($this->events, function (string $format, array $rows, array $fields): void {
            $this->printed[] = [$format, $rows, $fields];
        });
    }

    /** @param array<string, mixed> $document */
    private function file(array $document): string
    {
        $path = (string) wp_tempnam('kalaka-event-');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
        file_put_contents($path, (string) wp_json_encode($document));
        $this->files[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function lines(): array
    {
        $lines = [];
        foreach (\WP_CLI::$calls as [$kind, $message]) {
            $lines[] = $kind . ': ' . (is_array($message) ? (string) $message[0] : (string) $message);
        }

        return $lines;
    }

    private function fails(\Closure $call): string
    {
        \WP_CLI::reset();
        try {
            $call();
            self::fail('The command did not fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        $errors = array_values(array_filter(\WP_CLI::$calls, static fn (array $call): bool => $call[0] === 'error'));
        self::assertCount(1, $errors);

        return (string) $errors[0][1][0];
    }

    private function newId(): int
    {
        $this->command()->create(['Teszt tábor'], ['porcelain' => true]);
        $id = (int) \WP_CLI::$calls[0][1];
        $this->readyAccountMail($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function shown(int $id): array
    {
        \WP_CLI::reset();
        $this->command()->show([(string) $id], ['format' => 'json']);

        return json_decode((string) \WP_CLI::$calls[0][1], true);
    }

    /** @return array<string, mixed> an IVK-like setup: points in the site zone, accounts, 7 days, transfer details */
    private static function ivkLike(): array
    {
        return [
            'name' => 'Teszt ifjúsági tábor',
            'capacity' => 300,
            'registration_opens_at' => '2026-11-30 09:00',
            'registration_closes_at' => '2027-02-28 23:59',
            'starts_at' => '2027-03-25 12:00',
            'ends_at' => '2027-03-28 14:00',
            'points' => [['name' => 'Előleg határideje', 'at' => '2027-01-15 20:00']],
            'account_mode' => 'mandatory',
            'payment_due_days' => 7,
            'transfer_beneficiary' => 'Teszt Egyesület',
            'transfer_account' => '11111111-22222222-33333333',
            'transfer_reference_prefix' => 'TESZT26',
        ];
    }

    public function testCreateMakesADraftAndPrintsItsId(): void
    {
        $this->command()->create(['Teszt tábor'], []);

        self::assertCount(1, \WP_CLI::$calls);
        self::assertMatchesRegularExpression(
            '/\Asuccess: The event is created as a draft \(event \d+\)\.\z/',
            $this->lines()[0]
        );
        \WP_CLI::reset();
        $id = $this->newId();
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame('Teszt tábor', $event->name);
        self::assertSame(EventState::Draft, $event->state());
    }

    public function testCreateWithoutANameFails(): void
    {
        self::assertSame(self::INVALID, $this->fails(fn () => $this->command()->create(['  '], [])));
    }

    public function testUpdateFromAFileSetsTheWholeSetupAndShowPrintsItBack(): void
    {
        $id = $this->newId();
        \WP_CLI::reset();

        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);

        self::assertContains('success: The event is saved (version 2).', $this->lines());
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
        self::assertSame(7, $event->paymentDueDays);
        self::assertSame('TESZT26', $event->transferReferencePrefix);
        self::assertSame(300, $event->capacity);

        $shown = $this->shown($id);
        self::assertSame('2026-11-30 09:00', $shown['registration_opens_at']);
        self::assertSame('draft', $shown['state']);
        self::assertSame([['id' => 1, 'name' => 'Előleg határideje', 'at' => '2027-01-15 20:00']], $shown['points']);
    }

    public function testWhatShowPrintsUpdatesAsNothingToChange(): void
    {
        $id = $this->newId();
        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);
        $document = $this->shown($id);
        $version = $this->versionOf($id);

        \WP_CLI::reset();
        $this->command()->update([(string) $id], ['file' => $this->file($document)]);

        self::assertSame(['success: Nothing to change.'], $this->lines());
        self::assertSame($version, $this->versionOf($id));
    }

    public function testAFileLeavesOutWhatStaysAndUpdateListsTheChanges(): void
    {
        $id = $this->newId();
        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);

        \WP_CLI::reset();
        $changes = ['capacity' => 250, 'payment_due_days' => null];
        $this->command()->update([(string) $id], ['file' => $this->file($changes)]);

        self::assertSame(
            ['line: capacity: 300 -> 250', 'line: payment_due_days: 7 -> ', 'success: The event is saved (version 3).'],
            $this->lines()
        );
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(250, $event->capacity);
        self::assertNull($event->paymentDueDays);
        self::assertSame('Előleg határideje', $event->customPoints[0]->name);
        self::assertSame('Teszt Egyesület', $event->transferBeneficiary);
    }

    public function testADryRunSavesNothing(): void
    {
        $id = $this->newId();
        \WP_CLI::reset();

        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike()), 'dry-run' => true]);

        self::assertContains('line: capacity:  -> 300', $this->lines());
        self::assertContains('success: Nothing is saved (dry run).', $this->lines());
        self::assertSame(1, $this->versionOf($id));
    }

    public function testADryRunRunsTheUseCaseSoItRefusesWhatARealRunWould(): void
    {
        $id = $this->newId();
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $form = $this->insertForm($this->slotFields(), $registration);
        $path = $this->file(['account_mode' => 'mandatory']);
        $stale = $this->file(['name' => 'Más név', 'version' => 9]);

        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path, 'dry-run' => true]));
        self::assertSame(self::REFUSED . "\nforms without the account block: " . $form, $error);
        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $stale, 'dry-run' => true]));
        self::assertSame(self::CONFLICT, $error);
        self::assertSame(1, $this->versionOf($id));
    }

    public function testAStaleVersionInTheFileMeetsTheUseCasesConflictAndNothingChanges(): void
    {
        $id = $this->newId();
        $before = $this->row($id);
        $path = $this->file(['version' => 9, 'capacity' => 10]);

        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));

        self::assertSame(self::CONFLICT, $error);
        self::assertSame($before, $this->row($id));
    }

    public function testAModeWithAccountsNeedsTheRegisteredMailAndSaysWhy(): void
    {
        $this->command()->create(['Teszt tábor'], ['porcelain' => true]);
        $id = (int) \WP_CLI::$calls[0][1];
        $path = $this->file(['account_mode' => 'mandatory']);

        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));

        self::assertSame(self::REFUSED . "\nthe registered mail: " . AccountLinkRule::NEEDS_LINK, $error);
        self::assertSame(1, $this->versionOf($id));
    }

    public function testAVersionThatIsNotAnIntegerIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->newId();
        $before = $this->row($id);

        foreach (['1', 1.5, null, true, [1]] as $version) {
            $path = $this->file(['version' => $version, 'capacity' => 10]);
            $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));

            self::assertSame('"version" must be a whole number.', $error);
            self::assertSame($before, $this->row($id));
        }
    }

    public function testTheFilesOwnVersionIsTheOneTheUseCaseChecks(): void
    {
        $id = $this->newId();
        $document = $this->shown($id);
        \WP_CLI::reset();

        $path = $this->file(['version' => $document['version'], 'capacity' => 10]);
        $this->command()->update([(string) $id], ['file' => $path]);

        self::assertSame(2, $this->versionOf($id));
    }

    /** @return iterable<string, array{array<string, mixed>|string, string}> */
    public static function badFiles(): iterable
    {
        // The file's shape is the command's; every value is the use case's to refuse.
        yield 'unknown key' => [['colour' => 'red'], 'Unknown key "colour" in the file.'];
        yield 'a list' => ['[1, 2]', 'The file must hold one JSON object.'];
        yield 'not json' => ['{nope', 'The file is not valid JSON: Syntax error'];
        yield 'another state' => [
            ['state' => 'published'],
            '"state" cannot be changed here, or the file is out of date.',
        ];
        yield 'bad mode' => [['account_mode' => 'required'], self::INVALID];
        yield 'negative days' => [['payment_due_days' => -2], self::INVALID];
        yield 'long account' => [['transfer_account' => str_repeat('1', 65)], self::INVALID];
        yield 'time with seconds' => [['starts_at' => '2027-03-25 12:00:00'], self::INVALID];
        yield 'empty name' => [['name' => ' '], self::INVALID];
        yield 'capacity text' => [['capacity' => 'sok'], self::INVALID];
        yield 'a point without a time' => [['points' => [['name' => 'X']]], self::INVALID];
    }

    /**
     * @dataProvider badFiles
     * @param array<string, mixed>|string $document
     */
    public function testABadFileIsRefusedAndNothingChanges(array|string $document, string $message): void
    {
        $id = $this->newId();
        $before = $this->row($id);
        $path = is_array($document) ? $this->file($document) : $this->file([]);
        if (is_string($document)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file
            file_put_contents($path, $document);
        }

        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));

        self::assertStringStartsWith($message, $error);
        self::assertSame($before, $this->row($id));
    }

    public function testUpdateNeedsAnEventAndAReadableFile(): void
    {
        $id = $this->newId();

        self::assertSame(
            'The event ID must be a positive whole number.',
            $this->fails(fn () => $this->command()->update(['abc'], ['file' => 'x']))
        );
        self::assertSame(
            'There is no event 99999.',
            $this->fails(fn () => $this->command()->update(['99999'], ['file' => 'x']))
        );
        self::assertSame(
            'Give the changes with --file=<json>.',
            $this->fails(fn () => $this->command()->update([(string) $id], []))
        );
        self::assertSame(
            'The file cannot be read: /nope.json',
            $this->fails(fn () => $this->command()->update([(string) $id], ['file' => '/nope.json']))
        );
    }

    public function testTheModeIsRefusedByTheUseCaseWhileAPublishedFormLacksTheBlock(): void
    {
        $id = $this->newId();
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $form = $this->insertForm($this->slotFields(), $registration);
        $path = $this->file(['account_mode' => 'mandatory']);

        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));
        self::assertSame(self::REFUSED . "\nforms without the account block: " . $form, $error);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Off, $event->accountMode);
    }

    public function testShowAndListRefuseAUserWithoutFullAccessBeforeReadingAnything(): void
    {
        $id = $this->newId();
        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);
        $messages = [];
        foreach (['administrator', 'subscriber'] as $role) {
            wp_set_current_user(self::factory()->user->create(['role' => $role]));
            \WP_CLI::reset();
            $this->printed = [];
            $messages[] = $this->fails(fn () => $this->command()->show([(string) $id], ['format' => 'json']));
            $messages[] = $this->fails(fn () => $this->command()->show(['99999'], []));
            $messages[] = $this->fails(fn () => $this->command()->listEvents([], ['format' => 'json']));
            self::assertSame([], $this->printed);
        }
        wp_set_current_user(0);

        self::assertCount(1, array_unique($messages));
        self::assertStringNotContainsString('TESZT26', $messages[0]);
        // With no user the command line is full access.
        \WP_CLI::reset();
        $this->command()->show([(string) $id], ['format' => 'json']);
        self::assertStringContainsString('TESZT26', (string) \WP_CLI::$calls[0][1]);
    }

    public function testTheModeChangesOnAFormWithTheBlockAndTheBrokenListIsRefreshed(): void
    {
        $id = $this->newId();
        $registration = ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration'];
        $this->insertForm('<!-- wp:kalaka/account /-->' . $this->slotFields(), $registration);
        update_option(BrokenForms::OPTION, ['#0 stale: old'], false);

        $this->command()->update([(string) $id], ['file' => $this->file(['account_mode' => 'optional'])]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(AccountMode::Optional, $event->accountMode);
        self::assertSame([], BrokenForms::stored());
    }

    public function testAnActorWithoutAccessLearnsNothing(): void
    {
        $id = $this->newId();
        $this->insertForm($this->slotFields(), ['kalaka_event_id' => $id, 'kalaka_placement' => 'registration']);
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);
        $path = $this->file(['capacity' => 5, 'account_mode' => 'mandatory']);
        $denied = $this->fails(fn () => $this->command()->update(['99999'], ['file' => $path]));
        // The same refusal for an event that exists and one that does not, and for the transitions.
        self::assertSame($denied, $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path])));
        self::assertSame($denied, $this->fails(fn () => $this->command()->publish([(string) $id], [])));
        self::assertSame($denied, $this->fails(fn () => $this->command()->unpublish(['99999'], [])));
        self::assertSame($denied, $this->fails(fn () => $this->command()->archive([(string) $id], ['yes' => true])));
        self::assertStringNotContainsString('no event', $denied);
        self::assertStringNotContainsString('missing', $denied);
        self::assertStringNotContainsString('forms without', $denied);
        wp_set_current_user(0);
        self::assertSame(1, $this->versionOf($id));
    }

    public function testShowPrintsATableAndTheJsonDocument(): void
    {
        $id = $this->newId();
        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);

        \WP_CLI::reset();
        $this->command()->show([(string) $id], []);

        [$format, $rows, $fields] = $this->printed[0];
        self::assertSame('table', $format);
        self::assertSame(['field', 'value'], $fields);
        $byField = array_column($rows, 'value', 'field');
        self::assertSame((string) $id, $byField['id']);
        self::assertSame('draft', $byField['state']);
        self::assertSame('no', $byField['is_full']);
        self::assertSame('mandatory', $byField['account_mode']);
        self::assertSame('[{"id":1,"name":"Előleg határideje","at":"2027-01-15 20:00"}]', $byField['points']);

        $json = $this->shown($id);
        self::assertSame($id, $json['id']);
        self::assertSame('HUF', $json['currency']);
        self::assertSame(
            ['id', 'version', 'state', 'is_full', 'currency', 'name', 'capacity'],
            array_slice(array_keys($json), 0, 7)
        );
        self::assertSame(
            'The format must be table or json.',
            $this->fails(fn () => $this->command()->show([(string) $id], ['format' => 'csv']))
        );
    }

    public function testListPrintsEveryEventInEveryState(): void
    {
        $draft = $this->newId();
        $published = $this->published();
        $unpublished = $this->published();
        $this->transition(new UnpublishEvent(), $unpublished);

        \WP_CLI::reset();
        $this->command()->listEvents([], ['format' => 'json']);

        [$format, $rows, $fields] = $this->printed[0];
        self::assertSame('json', $format);
        self::assertSame(
            [
                'id', 'name', 'state', 'capacity', 'registration_opens_at', 'registration_closes_at', 'starts_at',
                'ends_at',
            ],
            $fields
        );
        $states = array_column($rows, 'state', 'id');
        self::assertSame('draft', $states[(string) $draft]);
        self::assertSame('published', $states[(string) $published]);
        self::assertSame('unpublished', $states[(string) $unpublished]);
        self::assertSame('2026-11-30 08:00', array_column($rows, 'registration_opens_at', 'id')[(string) $published]);
        self::assertSame(
            'The format must be table, json or csv.',
            $this->fails(fn () => $this->command()->listEvents([], ['format' => 'yaml']))
        );
    }

    public function testPublishIsRefusedByTheUseCaseUntilTheEventIsComplete(): void
    {
        $id = $this->newId();

        self::assertSame(
            self::REFUSED . "\nmissing: capacity, registration_opens_at, registration_closes_at, starts_at, ends_at",
            $this->fails(fn () => $this->command()->publish([(string) $id], []))
        );

        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);
        \WP_CLI::reset();
        $this->command()->publish([(string) $id], []);

        self::assertSame(['success: The event is published.'], $this->lines());
        self::assertSame('published', $this->shown($id)['state']);
        self::assertSame(self::REFUSED, $this->fails(fn () => $this->command()->publish([(string) $id], [])));
    }

    public function testUnpublishAndArchiveFollowTheLifecycle(): void
    {
        $id = $this->published();

        self::assertSame(
            self::REFUSED,
            $this->fails(fn () => $this->command()->archive([(string) $id], ['yes' => true]))
        );
        \WP_CLI::reset();
        $this->command()->unpublish([(string) $id], []);
        self::assertSame(['success: The event is unpublished.'], $this->lines());
        self::assertSame(self::REFUSED, $this->fails(fn () => $this->command()->unpublish([(string) $id], [])));

        // Archiving is for good: it needs --yes.
        self::assertSame(
            'Archiving makes the event read-only for good. Run it again with --yes to archive it.',
            $this->fails(fn () => $this->command()->archive([(string) $id], []))
        );
        self::assertSame('unpublished', $this->shown($id)['state']);
        \WP_CLI::reset();
        $this->command()->archive([(string) $id], ['yes' => true]);
        self::assertSame(['success: The event is archived.'], $this->lines());
        self::assertSame('archived', $this->shown($id)['state']);
    }

    public function testAnArchivedEventCannotBeChangedFromAFile(): void
    {
        $id = $this->published();
        $this->command()->unpublish([(string) $id], []);
        $this->command()->archive([(string) $id], ['yes' => true]);

        $path = $this->file(['capacity' => 5]);
        $error = $this->fails(fn () => $this->command()->update([(string) $id], ['file' => $path]));

        self::assertSame(self::REFUSED, $error);
        self::assertSame(41, $this->events->find($id)?->capacity);
    }

    public function testAnIvkLikeEventIsCreatedSetUpAndOpenedThroughTheCommandAlone(): void
    {
        $id = $this->newId();
        $this->command()->update([(string) $id], ['file' => $this->file(self::ivkLike())]);
        $this->command()->publish([(string) $id], []);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(EventState::Published, $event->state());
        self::assertSame('Teszt ifjúsági tábor', $event->name);
        $this->assertUtc('2026-11-30 08:00:00', $event->registrationOpens);
        $this->assertUtc('2027-02-28 22:59:00', $event->registrationCloses);
        $utc = new \DateTimeZone('UTC');
        self::assertFalse($event->registrationWindowContains(new \DateTimeImmutable('2026-11-30 07:59:00', $utc)));
        self::assertTrue($event->registrationWindowContains(new \DateTimeImmutable('2026-11-30 08:00:00', $utc)));
        // 7 days after the registration of 2026-12-01 (local): the end of 2026-12-08.
        $due = $event->paymentDue(new \DateTimeImmutable('2026-12-01 10:00:00', new \DateTimeZone('UTC')));
        self::assertNotNull($due);
        $this->assertUtc('2026-12-08 23:00:00', $due);
        self::assertSame(AccountMode::Mandatory, $event->accountMode);
        self::assertSame('Teszt Egyesület', $event->transferBeneficiary);
        self::assertSame('11111111-22222222-33333333', $event->transferAccount);
        self::assertSame('TESZT26', $event->transferReferencePrefix);
        // Every write was one use case run as the command line, audited.
        // (the mail the account mode needs was set up by the test, as the admin, and is left out).
        $entries = array_filter(
            $this->entries(),
            static fn (array $e): bool => $e['action'] !== 'kalaka/update-action-mail'
        );
        self::assertSame(
            ['kalaka/create-event', 'kalaka/update-event', 'kalaka/publish-event'],
            array_values(array_unique(array_column($entries, 'action')))
        );
        self::assertSame(['cli'], array_values(array_unique(array_column($entries, 'actor_type'))));
    }

    private function assertUtc(string $expected, ?\DateTimeImmutable $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame($expected, $actual->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }
}
