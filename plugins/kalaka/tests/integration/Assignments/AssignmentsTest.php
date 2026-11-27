<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Assignments;

use Kalaka\Assignments\Api\AssignmentSeats;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Assignments\Cli\AssignmentCommand;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Assignments\SetOptionClaimSource;
use Kalaka\Events\Api\Events;
use Kalaka\Events\CreateEvent;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;
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
            'window_opens_at' => '2026-12-01 08:00',
            'window_closes_at' => '2027-02-15 20:00',
            'published' => false,
        ], new CliActor(null));
    }

    /** @param array<string, mixed> $changes */
    private function newOption(int $set, string $label, array $changes = []): string
    {
        return $this->sets->saveOption($changes + [
            'set_id' => $set,
            'label' => $label,
            'limit' => 30,
            'leaders' => 'Teszt Vezető',
            'place' => 'Nagyterem',
            'position' => 0,
        ], new CliActor(null));
    }

    public function testASetIsStoredWithItsOptionsInTheirOrderAndItsWindowInUtc(): void
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
        self::assertSame('2026-12-01 07:00', $stored->windowOpens?->format('Y-m-d H:i'));
        self::assertSame(['modul_a', 'iskolamisszio', 'pro'], array_map(static fn ($o) => $o->key, $stored->options));
        self::assertSame([$set], array_map(static fn ($s) => $s->id, $this->sets->ofEvent($this->event)));
    }

    public function testAnOptionsLimitIsReadUnderTheSameNameItWasGiven(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 12]);
        $this->newOption($set, 'Modul B', ['limit' => null]);

        $stored = $this->sets->find($set);

        self::assertNotNull($stored);
        self::assertSame(12, $stored->option('modul_a')?->limit);
        self::assertNull($stored->option('modul_b')?->limit);
    }

    public function testTheWindowHoldsFromItsOpeningUpToItsClosing(): void
    {
        $set = $this->sets->find($this->newSet());
        self::assertNotNull($set);
        self::assertFalse($set->windowContains($this->utc('2026-12-01 06:59:59')));
        self::assertTrue($set->windowContains($this->utc('2026-12-01 07:00:00')));
        self::assertTrue($set->windowContains($this->utc('2027-02-15 18:59:59')));
        self::assertFalse($set->windowContains($this->utc('2027-02-15 19:00:00')));
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

    public function testAWindowThatClosesBeforeItOpensIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->newSet(['window_opens_at' => '2027-03-01 08:00']);
    }

    public function testTheCoreSeatClaimSourceNamesThePoolsOfTheOptionsByTheRequestFieldKeyAndClaimsNone(): void
    {
        $tied = $this->newSet();
        $this->newOption($tied, 'Modul A', ['limit' => 0]);
        $this->newOption($tied, 'Modul B', ['limit' => null]);
        $untied = $this->newSet(['name' => 'Délután']);
        $this->newOption($untied, 'Modul C');
        $this->sets->tieRequestField($tied, 'modulok', new CliActor(null));
        $event = (new Events())->find($this->event);
        self::assertNotNull($event);

        $source = new SetOptionClaimSource();
        $entries = $source->pools($event);

        self::assertSame(
            [
                ['modulok', 'modul_a', 'option.' . $tied . '.modul_a'],
                ['modulok', 'modul_b', 'option.' . $tied . '.modul_b'],
            ],
            array_map(static fn (PoolEntry $e): array => [$e->fieldKey, $e->optionValue, $e->pool->key()], $entries)
        );
        $answers = new ValidatedAnswers(['modulok' => 'modul_a'], ['modulok' => 'modul_a']);
        self::assertSame([], $source->claims($event, $answers));
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
        $this->newOption($set, 'Modul A', ['limit' => 1]);
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
        // Another registration would exceed the limit of one.
        self::assertSame(
            ['Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.'],
            $warn($second, 'modul_a')
        );

        // Warnings never stop the placing (staff override), and an unknown option has none.
        $this->place($second, $set, 'modul_a');
        self::assertSame('modul_a', $this->sets->assignment($second, $set)?->optionKey);
        self::assertSame([], $warn($second, 'nincs'));
    }

    public function testARegistrationThatTakesNoSeatIsNotWarnedThatTheOptionIsFull(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $waiting = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $waiting);
        $this->place($first, $set, 'modul_a');
        self::assertSame(
            ['Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.'],
            $this->sets->placementWarnings($waiting, $set, 'modul_a')
        );

        $this->setStatus($waiting, RegistrationStatus::Waitlisted);

        self::assertSame([], $this->sets->placementWarnings($waiting, $set, 'modul_a'));
    }

    public function testAWarningIsNotComputedForARegistrationThePlacingWouldRefuse(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $missing = new RegistrationKey($this->event, '00A0');

        self::assertSame([], $this->sets->placementWarnings($missing, $set, 'modul_a'));

        $this->expectException(NotFound::class);
        $this->place($missing, $set, 'modul_a');
    }

    public function testAWarningIsNotComputedForASetOfAnotherEvent(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
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
        $this->newOption($set, 'Modul A', ['limit' => 1]);
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

    public function testPlacingTakesASeatInTheOptionsPoolAndStaffMayGoOverTheCapacity(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $second = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $second);
        $seats = new Seats();
        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);
        self::assertSame('option.' . $set . '.modul_a', $pool->key());
        self::assertSame(1, $seats->left($pool));

        $this->place($first, $set, 'modul_a');
        self::assertSame(0, $seats->left($pool));
        self::assertTrue($seats->holds($pool, $first));

        // Staff go over: the placing is warned about, never refused.
        $this->place($second, $set, 'modul_a');
        self::assertSame(2, $seats->held($pool));
        self::assertSame(1, $seats->over($pool));
        self::assertSame(0, $seats->left($pool));
    }

    public function testPlacingTheSameOptionAgainKeepsOneSeat(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        $this->place($key, $set, 'modul_a');
        $this->place($key, $set, 'modul_a');

        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);
        self::assertSame(1, (new Seats())->held($pool));
        self::assertSame([], $this->sets->placementWarnings($key, $set, 'modul_a'));
    }

    public function testPlacingElsewhereFreesTheOldSeatAndNobodyMovesIn(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $this->newOption($set, 'Modul B', ['limit' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $second = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $second);
        $seats = new Seats();
        $a = $this->sets->seatPool($set, 'modul_a');
        $b = $this->sets->seatPool($set, 'modul_b');
        self::assertNotNull($a);
        self::assertNotNull($b);
        $this->place($first, $set, 'modul_a');

        $this->place($first, $set, 'modul_b');

        self::assertSame(0, $seats->held($a));
        self::assertSame(1, $seats->held($b));
        self::assertFalse($seats->holds($a, $second));
        self::assertSame('modul_b', $this->sets->assignment($first, $set)?->optionKey);
    }

    public function testAWithdrawalFreesTheOptionSeatAndTheNextPlacingTakesIt(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $first = new RegistrationKey($this->event, '00A0');
        $second = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($first, $second);
        $seats = new Seats();
        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);
        $this->place($first, $set, 'modul_a');
        self::assertNotSame([], $this->sets->placementWarnings($second, $set, 'modul_a'));

        $this->inRun(function (array $input, Context $context) use ($seats, $first): void {
            (new Registrations())->lock($first, $context);
            $seats->releaseAll($first, $context);
        });

        self::assertSame(1, $seats->left($pool));
        self::assertFalse($seats->holds($pool, $second));
        self::assertSame([], $this->sets->placementWarnings($second, $set, 'modul_a'));
        // The assignment is the choice; the seat was the place: the choice stays, nobody else took the seat.
        self::assertSame('modul_a', $this->sets->assignment($first, $set)?->optionKey);
        $this->place($second, $set, 'modul_a');
        self::assertSame(0, $seats->left($pool));
    }

    public function testAnOptionWithoutALimitHasNoSeatsLeftFigure(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => null]);

        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);
        self::assertNull((new Seats())->left($pool));
        self::assertNull($this->sets->seatPool($set, 'nincs'));
        self::assertNull($this->sets->seatPool($set + 1000, 'modul_a'));
    }

    public function testPlacingAWaitlistedRegistrationWritesTheAssignmentAndTakesNoSeat(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $waiting = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($waiting);
        $this->setStatus($waiting, RegistrationStatus::Waitlisted);
        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);

        $this->place($waiting, $set, 'modul_a');

        $assignment = $this->sets->assignment($waiting, $set);
        self::assertNotNull($assignment);
        self::assertSame('modul_a', $assignment->optionKey);
        self::assertSame(AssignmentState::Confirmed, $assignment->state);
        self::assertFalse((new Seats())->holds($pool, $waiting));
        self::assertSame(1, (new Seats())->left($pool));
    }

    public function testAnAdmissionTakesTheSeatsOfTheConfirmedAssignmentsAndAFullOptionGoesOverAndIsReported(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $other = $this->newSet(['name' => 'Délután']);
        $this->newOption($other, 'Modul B', ['limit' => 1]);
        $waiting = new RegistrationKey($this->event, '00A0');
        $holder = new RegistrationKey($this->event, '00A2');
        $this->seedRegistrations($waiting, $holder);
        $this->setStatus($waiting, RegistrationStatus::Waitlisted);
        $this->place($waiting, $set, 'modul_a');
        $this->place($waiting, $other, 'modul_b');
        $this->place($holder, $other, 'modul_b');
        $seats = new Seats();
        $a = $this->sets->seatPool($set, 'modul_a');
        $b = $this->sets->seatPool($other, 'modul_b');
        self::assertNotNull($a);
        self::assertNotNull($b);
        $this->setStatus($waiting, RegistrationStatus::Registered);

        $full = $this->inRun(function (array $input, Context $context) use ($waiting): array {
            (new Registrations())->lock($waiting, $context);

            return (new AssignmentSeats())->retake($waiting, SeatAct::Admission, $context);
        });

        self::assertTrue($seats->holds($a, $waiting));
        self::assertTrue($seats->holds($b, $waiting));
        self::assertSame(2, $seats->held($b));
        self::assertSame(1, $seats->over($b));
        self::assertCount(1, $full);
        self::assertSame([$other, 'modul_b'], [$full[0]->setId, $full[0]->optionKey]);
        // The seat went over the limit; nobody was moved out of the full option.
        self::assertSame('modul_b', $this->sets->assignment($waiting, $other)?->optionKey);
        self::assertTrue($seats->holds($b, $holder));
    }

    public function testAWithdrawnRegistrationKeepsItsAssignmentAndARestoreTakesTheSeatAgain(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A', ['limit' => 1]);
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);
        $seats = new Seats();
        $pool = $this->sets->seatPool($set, 'modul_a');
        self::assertNotNull($pool);
        $this->place($key, $set, 'modul_a');
        $this->inRun(function (array $input, Context $context) use ($seats, $key): void {
            (new Registrations())->lock($key, $context);
            $seats->releaseAll($key, $context);
        });
        $this->setStatus($key, RegistrationStatus::Withdrawn);
        // Staff place a withdrawn registration again: the assignment is written, no seat is taken.
        $this->place($key, $set, 'modul_a');
        self::assertFalse($seats->holds($pool, $key));
        self::assertSame(1, $seats->left($pool));
        $this->setStatus($key, RegistrationStatus::Registered);

        $full = $this->inRun(function (array $input, Context $context) use ($key): array {
            (new Registrations())->lock($key, $context);

            return (new AssignmentSeats())->retake($key, SeatAct::Restore, $context);
        });

        self::assertSame([], $full);
        self::assertTrue($seats->holds($pool, $key));
        self::assertSame(0, $seats->left($pool));
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
