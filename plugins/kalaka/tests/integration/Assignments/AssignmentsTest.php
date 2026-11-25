<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Assignments;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Assignments\Cli\AssignmentCommand;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Registrations\RegistrationsTestCase;

/**
 * S44: request-mode assignment sets, their options, the tie
 * to the one request field (S44-1), and staff placing a registration by hand (an audited, confirmed fact,
 * at most one per set).
 */
final class AssignmentsTest extends RegistrationsTestCase
{
    private AssignmentSets $sets;

    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        $this->sets = new AssignmentSets($this->runner);
        $this->event = (int) $this->runner->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
    }

    /** @param array<string, mixed> $changes */
    private function newSet(array $changes = []): int
    {
        return $this->sets->save($changes + [
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => true,
            'published' => false,
        ], new CliActor(null));
    }

    /** @param array<string, mixed> $changes */
    private function newOption(int $set, string $label, array $changes = []): string
    {
        return $this->sets->saveOption($changes + [
            'set_id' => $set,
            'label' => $label,
            'capacity' => 30,
            'leaders' => 'Teszt Vezető',
            'place' => 'Nagyterem',
            'position' => 0,
        ], new CliActor(null));
    }

    public function testASetIsStoredWithItsOptionsInTheirOrder(): void
    {
        $set = $this->newSet(['time_slot' => 'Délelőtt']);
        self::assertSame('pro', $this->newOption($set, 'PRO', ['position' => 8]));
        self::assertSame('modul_a', $this->newOption($set, 'Modul A', ['position' => 1]));
        self::assertSame('iskolamisszio', $this->newOption($set, 'Iskolamisszió', [
            'position' => 7,
        ]));

        $stored = $this->sets->find($set);
        self::assertNotNull($stored);
        self::assertSame('Modulok', $stored->name);
        self::assertSame('Délelőtt', $stored->timeSlot);
        self::assertTrue($stored->required);
        self::assertFalse($stored->published);
        self::assertNull($stored->requestFieldKey);
        self::assertSame(['modul_a', 'iskolamisszio', 'pro'], array_map(static fn ($o) => $o->key, $stored->options));
        self::assertSame([$set], array_map(static fn ($s) => $s->id, $this->sets->ofEvent($this->event)));
    }

    public function testAnOptionKeyIsDerivedOnceAndNeverChanges(): void
    {
        $set = $this->newSet();
        self::assertSame('modul_a', $this->newOption($set, 'Modul A'));
        self::assertSame('modul_a_1', $this->newOption($set, 'Modul A'));

        $this->newOption($set, 'Modul Á, átnevezve', ['key' => 'modul_a', 'version' => 1]);
        self::assertSame('Modul Á, átnevezve', $this->sets->find($set)?->option('modul_a')?->label);
    }

    public function testAnOptionUpdateNeedsTheVersionItWasReadAt(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');

        $this->expectException(VersionConflict::class);
        $this->newOption($set, 'Modul A', ['key' => 'modul_a']);
    }

    public function testASetOfAMissingEventIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->newSet(['event_id' => $this->event + 1000]);
    }

    public function testASetHasNoWindowAndTheInputRefusesOne(): void
    {
        $this->expectException(InvalidInput::class);
        $this->newSet(['window_opens_at' => '2027-03-01 08:00']);
    }

    public function testTheFirstRequestFieldIsTiedAndTheSameOneAgainChangesNothing(): void
    {
        $set = $this->newSet();
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));

        $stored = $this->sets->find($set);
        self::assertSame('modulok', $stored?->requestFieldKey);
        self::assertSame(2, $stored->version);
    }

    public function testASecondDifferentRequestFieldIsRefused(): void
    {
        $set = $this->newSet();
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));

        $this->expectException(RuleViolation::class);
        $this->sets->tieRequestField($set, 'modulok_2', new CliActor(null));
    }

    public function testOneFieldIsTheRequestFieldOfOneSetOnly(): void
    {
        $first = $this->newSet();
        $second = $this->newSet(['name' => 'Délutáni modulok']);
        $this->sets->tieRequestField($first, 'modulok', new CliActor(null));

        $this->expectException(RuleViolation::class);
        $this->sets->tieRequestField($second, 'modulok', new CliActor(null));
    }

    public function testStaffPlaceARegistrationAsAConfirmedFactAuditedOnTheRegistration(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->newOption($set, 'PRO');
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        // C6: staff place by hand; only capacity is warned about.
        $this->place($key, $set, 'pro');

        $assignment = $this->sets->assignment($key, $set);
        self::assertSame('pro', $assignment?->optionKey);
        self::assertSame(AssignmentSource::Staff, $assignment->source);
        self::assertSame(AssignmentState::Confirmed, $assignment->state);

        $entries = $this->entries();
        $entry = $entries[array_key_last($entries)];
        self::assertSame('kalaka/place-registration', $entry['action']);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf((int) $entry['id']));
        $fields = array_column($this->decode($entry['changes']), 'field');
        self::assertContains('assignment:' . $set . '.option_key', $fields);
    }

    public function testPlacingAgainOverridesTheOneAssignmentOfTheSet(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->newOption($set, 'Modul B');
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        $this->place($key, $set, 'modul_a');
        $this->place($key, $set, 'modul_b');

        global $wpdb;
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_assignments WHERE event_id = %d",
            $this->event
        )));
        self::assertSame('modul_b', $this->sets->assignment($key, $set)?->optionKey);
    }

    public function testStaffAreWarnedBeforePlacingIntoAFullOption(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['capacity' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $second = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $second);
        $warn = fn (RegistrationKey $key, string $option): array => $this->sets->placementWarnings(
            $key,
            $set,
            $option
        );

        // Nobody holds Modul A yet: nothing to warn about, and a registration's own place never makes it full.
        self::assertSame([], $warn($second, 'modul_a'));
        $this->place($first, $set, 'modul_a');
        self::assertSame([], $warn($first, 'modul_a'));
        // Another registration would exceed the capacity of one.
        self::assertSame(
            ['Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.'],
            $warn($second, 'modul_a')
        );

        // Warnings never stop the placing (staff override), and an unknown option has none.
        $this->place($second, $set, 'modul_a');
        self::assertSame('modul_a', $this->sets->assignment($second, $set)?->optionKey);
        self::assertSame([], $warn($second, 'nincs'));
    }

    public function testAWarningIsNotComputedForARegistrationThePlacingWouldRefuse(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['capacity' => 1]);
        $missing = new RegistrationKey($this->event, '00A0');

        self::assertSame([], $this->sets->placementWarnings($missing, $set, 'modul_a'));

        $this->expectException(NotFound::class);
        $this->place($missing, $set, 'modul_a');
    }

    public function testAWarningIsNotComputedForASetOfAnotherEvent(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['capacity' => 1]);
        $other = new RegistrationKey($this->event + 1, '00A0');
        $this->seedRegistrations($other);

        self::assertSame([], $this->sets->placementWarnings($other, $set, 'modul_a'));

        $this->expectException(NotFound::class);
        $this->place($other, $set, 'modul_a');
    }

    public function testACliUserWhoMayNotPlaceIsRefusedBeforeAnyWarning(): void
    {
        // WP-CLI is not loaded in PHPUnit: a recording double stands in for it.
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        if (defined('WP_CLI::IS_TEST_DOUBLE')) {
            \WP_CLI::reset();
        }

        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['capacity' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $second = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $second);
        $this->place($first, $set, 'modul_a');
        self::assertNotSame([], $this->sets->placementWarnings($second, $set, 'modul_a'));

        wp_set_current_user(self::factory()->user->create());
        try {
            (new AssignmentCommand())->place(
                [(string) $this->event, '00A2', (string) $set, 'modul_a'],
                []
            );
            self::fail('The command should have refused the user.');
        } catch (\RuntimeException) {
            // The double's error() does not return.
        } finally {
            wp_set_current_user(0);
        }

        self::assertSame([], array_values(array_filter(
            \WP_CLI::$calls,
            static fn (array $call): bool => $call[0] === 'warning'
        )));
        self::assertSame('error', \WP_CLI::$calls[0][0]);
    }

    public function testAnOptionOfAnotherSetIsRefused(): void
    {
        $set = $this->newSet();
        $other = $this->newSet(['name' => 'Másik']);
        $this->newOption($other, 'Modul A');
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        $this->expectException(InvalidInput::class);
        $this->place($key, $set, 'modul_a');
    }

    public function testASetOfAnotherEventIsNotFound(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $key = new RegistrationKey($this->event + 1, '00A0');
        $this->seedRegistrations($key);

        $this->expectException(NotFound::class);
        $this->place($key, $set, 'modul_a');
    }

    public function testAMissingRegistrationIsNotFound(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');

        $this->expectException(NotFound::class);
        $this->place(new RegistrationKey($this->event, '00A0'), $set, 'modul_a');
    }

    public function testTheEditorReadsTheEventsSetsWithFullAccessOnly(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));
        $GLOBALS['wp_rest_server'] = null;
        $request = new \WP_REST_Request('GET', '/kalaka-admin/v1/events/' . $this->event . '/assignment-sets');

        wp_set_current_user(self::factory()->user->create());
        self::assertSame(403, rest_do_request($request)->get_status());

        wp_set_current_user($this->fullAccessUser());
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertSame([[
            'id' => $set,
            'name' => 'Modulok',
            'request_field_key' => 'modulok',
            'options' => [['value' => 'modul_a', 'label' => 'Modul A']],
        ]], $response->get_data());
        wp_set_current_user(0);
        $GLOBALS['wp_rest_server'] = null;
    }

    private function place(RegistrationKey $key, int $set, string $option): void
    {
        $this->runner->run(new PlaceRegistration(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'set_id' => $set,
            'option' => $option,
        ], new CliActor(null));
    }
}
