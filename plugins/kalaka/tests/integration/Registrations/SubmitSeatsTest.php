<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\OptionSeatPool;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Events\Api\Event;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\SeatOutcome;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SecretLink;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\TestItemRepository;

/**
 * A submit that chooses a limited option takes its seat when it becomes `registered` and is refused, with
 * nothing saved, when the pool is full (topic 15, Q2). A submit that lands on the waitlist may choose
 * nothing limited (O4/A3).
 */
final class SubmitSeatsTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

    private int $event = 0;

    private int $set = 0;

    private int $form = 0;

    private Seats $seats;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->seats = new Seats();
    }

    /** An open event with the given capacity and one set holding the option `modul_a` with the given places. */
    private function openEvent(int $capacity, int $places): void
    {
        $this->event = $this->create();
        $this->update($this->event, ['capacity' => $capacity]);
        $this->transition(new PublishEvent(), $this->event);
        $this->form = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $sets = new AssignmentSets($this->runner);
        $this->set = $sets->save([
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => false,
            'window_opens_at' => null,
            'window_closes_at' => null,
            'published' => false,
        ], new CliActor(null));
        $sets->saveOption([
            'set_id' => $this->set,
            'label' => 'Modul A',
            'limit' => $places,
            'leaders' => '',
            'place' => '',
            'condition' => null,
            'position' => 0,
        ], new CliActor(null));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        foreach (['kalaka_test_items', 'kalaka_test_items_other'] as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup; the statement is built from the table prefix and a constant only
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }

        parent::tear_down();
    }

    private function submit(): SubmitResult
    {
        return $this->submitClaiming([new SeatClaim('modul', $this->pool())]);
    }

    /** @param list<SeatClaim> $claims */
    private function submitClaiming(array $claims): SubmitResult
    {
        $form = $this->readForm($this->form);
        $useCase = new SubmitRegistration(
            $form,
            $form->version,
            new ValidatedAnswers(self::ANSWERS, self::ANSWERS),
            $this->versionOf($this->event),
            [],
            false,
            [],
            (new ParticipantFields())->of($this->event),
            seatClaims: $claims,
        );
        $result = $this->runner->run(
            $useCase,
            ['form' => $form->id, 'token' => SubmitToken::generate()],
            new GuestActor()
        );
        self::assertInstanceOf(SubmitResult::class, $result);

        return $result;
    }

    /** @param \Closure(Context): void $work */
    private function inAdminRun(\Closure $work): void
    {
        $this->runner->run(
            new AdminProbe(fn (array $input, Context $context) => $work($context)),
            [],
            new CliActor(null)
        );
    }

    private function pool(): OptionSeatPool
    {
        return new OptionSeatPool($this->event, $this->set, 'modul_a');
    }

    private function registrationCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $this->event
        ));
    }

    public function testARegisteredSubmitTakesTheSeatOfItsChoice(): void
    {
        $this->openEvent(10, 2);

        $result = $this->submit();

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertTrue($this->seats->holds($this->pool(), $result->registration));
        self::assertSame(1, $this->seats->left($this->pool()));
    }

    public function testASubmitChoosingAFullOptionIsRefusedAndNothingIsSaved(): void
    {
        $this->openEvent(10, 1);
        $first = $this->submit();
        self::assertSame(RegistrationStatus::Registered, $first->status);

        try {
            $this->submit();
            self::fail('The full choice should have refused the submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['modul' => 'Ez a lehetőség időközben betelt. Válassz másikat.'], $invalid->fields);
        }

        self::assertSame(1, $this->registrationCount());
        self::assertSame(1, $this->seats->held($this->pool()));
        self::assertSame(0, $this->seats->over($this->pool()));
    }

    public function testAFreedSeatTakenByTheNextSubmitIsThatSubmitsOwnAct(): void
    {
        $this->openEvent(10, 1);
        $first = $this->submit();
        $this->inAdminRun(function (Context $context) use ($first): void {
            (new Registrations())->lock($first->registration, $context);
            $this->seats->releaseAll($first->registration, $context);
        });

        $second = $this->submit();

        self::assertTrue($this->seats->holds($this->pool(), $second->registration));
        self::assertFalse($this->seats->holds($this->pool(), $first->registration));
    }

    public function testStaffPlacedOverTheLimitMakeTheNextSubmitRefused(): void
    {
        $this->openEvent(10, 1);
        $first = $this->submit();
        // Staff place another registration by hand: over the limit.
        $other = new RegistrationKey($this->event, '00A0');
        $this->inAdminRun(function (Context $context): void {
            $created = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));
            (new RegistrationRepository())->upsert(new Registration(
                new RegistrationKey($this->event, '00A0'),
                RegistrationStatus::Registered,
                SecretLink::generate(),
                $created,
                $created
            ), $context);
        });
        $this->runner->run(new PlaceRegistration(), [
            'event_id' => $this->event,
            'code' => $other->code,
            'set_id' => $this->set,
            'option' => 'modul_a',
        ], new CliActor(null));
        self::assertSame(1, $this->seats->over($this->pool()));
        // The first leaves: the pool is still full because of the over-placing.
        $this->inAdminRun(function (Context $context) use ($first): void {
            (new Registrations())->lock($first->registration, $context);
            $this->seats->releaseAll($first->registration, $context);
        });

        $this->expectException(InvalidAnswers::class);
        $this->submit();
    }

    public function testAFullEventRefusesASubmitWithALimitedChoiceAndWritesNothing(): void
    {
        // Owner ruling 2026-11-25 (O4/A3): the event is full, so everything limited acts as full, though the option
        // itself has places left.
        $this->openEvent(1, 5);
        $first = $this->submitClaiming([]);
        self::assertSame(RegistrationStatus::Registered, $first->status);
        self::assertSame(0, $this->seats->left($this->seats->eventPool($this->event)));

        try {
            $this->submit();
            self::fail('The limited choice should have refused the waitlisted submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['modul' => SubmitRegistration::WAITLIST_LIMITED_TEXT], $invalid->fields);
            self::assertSame(['modul' => 'kalaka_waitlist_limited'], $invalid->codes);
            self::assertSame('kalaka_waitlist_limited', $invalid->toWpError()->get_error_data()['codes']['modul']);
        }

        self::assertSame(1, $this->registrationCount());
        self::assertSame(0, $this->seats->held($this->pool()));
        self::assertSame(1, $this->submissionCount());
    }

    public function testALatchedEventRefusesALimitedChoiceEvenWithPlacesFree(): void
    {
        $this->openEvent(2, 5);
        $this->submitClaiming([]);
        $this->inAdminRun(function (Context $context): void {
            (new \Kalaka\Events\Api\EventCapacity())->markFull($context, $this->event);
        });
        self::assertSame(0, $this->seats->left($this->seats->eventPool($this->event)));

        $this->expectException(InvalidAnswers::class);
        $this->submit();
    }

    public function testAFullEventPutsASubmitWithOnlyUnlimitedChoicesOnTheWaitlist(): void
    {
        $this->openEvent(1, 1);
        $this->submitClaiming([]);
        [$nine] = $this->quotaPools();
        $free = new TestQuotaPool($this->event, 'tier.free', 'kalaka_test_items', 1, null);

        $second = $this->submitClaiming([new SeatClaim('a', $free)]);

        self::assertSame(RegistrationStatus::Waitlisted, $second->status);
        self::assertFalse($this->seats->holds($free, $second->registration));
        self::assertSame(2, $this->registrationCount());
        self::assertSame(0, $this->seats->held($nine));
    }

    public function testAnEventWithRoomStillTakesTheLimitedChoice(): void
    {
        $this->openEvent(5, 1);

        $result = $this->submit();

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertTrue($this->seats->holds($this->pool(), $result->registration));
    }

    private function submissionCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_submissions WHERE event_id = %d",
            $this->event
        ));
    }

    /**
     * Test tables of quota rows: `kalaka_test_items` with ten rows, `kalaka_test_items_other` with one. A pool over
     * a row is a quota-level pool, as a price tier's or an offer's will be.
     *
     * @return array{TestQuotaPool, TestQuotaPool, TestQuotaPool} the rows 9 and 10 of the first table, then the
     *     other table's row, each with a single seat
     */
    private function quotaPools(): array
    {
        global $wpdb;
        TestItemRepository::create('kalaka_test_items');
        TestItemRepository::create('kalaka_test_items_other');
        for ($row = 1; $row <= 10; $row++) {
            $wpdb->insert($wpdb->prefix . 'kalaka_test_items', ['label' => 'Sor ' . $row, 'version' => 1]);
        }
        $wpdb->insert($wpdb->prefix . 'kalaka_test_items_other', ['label' => 'Egyéb', 'version' => 1]);

        return [
            new TestQuotaPool($this->event, 'tier.9', 'kalaka_test_items', 9, 1),
            new TestQuotaPool($this->event, 'tier.10', 'kalaka_test_items', 10, 1),
            new TestQuotaPool($this->event, 'offer.1', 'kalaka_test_items_other', 1, 1),
        ];
    }

    public function testQuotaClaimsAreLockedInTheRunnersOrderNotByPoolKey(): void
    {
        $this->openEvent(10, 2);
        [$nine, $ten, $other] = $this->quotaPools();

        // As text "offer.1" < "tier.10" < "tier.9"; the guard wants the first table's rows 9, 10, then the other table.
        $result = $this->submitClaiming([
            new SeatClaim('a', $other),
            new SeatClaim('b', $ten),
            new SeatClaim('c', $nine),
        ]);

        self::assertSame(RegistrationStatus::Registered, $result->status);
        foreach ([$nine, $ten, $other] as $pool) {
            self::assertTrue($this->seats->holds($pool, $result->registration), $pool->key());
        }
    }

    public function testAFullQuotaPoolRefusesTheSubmitBeforeAnythingIsWrittenAndTheEarlierPoolsKeepNothing(): void
    {
        $this->openEvent(10, 2);
        [$nine, $ten] = $this->quotaPools();
        $this->submitClaiming([new SeatClaim('b', $ten)]);

        try {
            $this->submitClaiming([new SeatClaim('a', $nine), new SeatClaim('b', $ten)]);
            self::fail('The full quota pool should have refused the submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['b' => 'Ez a lehetőség időközben betelt. Válassz másikat.'], $invalid->fields);
        }

        self::assertSame(1, $this->registrationCount());
        self::assertSame(0, $this->seats->held($nine));
        self::assertSame(1, $this->seats->held($ten));
    }

    public function testARestoreIntoAFullOptionTakesTheSeatOverTheLimit(): void
    {
        $this->openEvent(10, 1);
        $this->submit();
        $other = new RegistrationKey($this->event, '00A0');
        $this->inAdminRun(function (Context $context) use ($other): void {
            $created = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));
            (new RegistrationRepository())->upsert(new Registration(
                $other,
                RegistrationStatus::Registered,
                SecretLink::generate(),
                $created,
                $created
            ), $context);
        });

        $this->inAdminRun(function (Context $context) use ($other): void {
            (new Registrations())->lock($other, $context);
            self::assertSame(
                SeatOutcome::Over,
                $this->seats->retake($this->pool(), $other, SeatAct::Restore, $context)
            );
        });

        self::assertSame(2, $this->seats->held($this->pool()));
        self::assertSame(1, $this->seats->over($this->pool()));
        self::assertTrue($this->seats->holds($this->pool(), $other));
    }

    public function testTheContributedSeatClaimSourceFeedsTheSubmit(): void
    {
        $this->openEvent(10, 1);
        $pool = $this->pool();
        $source = new class ($pool) implements SeatClaimSource {
            public function __construct(private readonly OptionSeatPool $pool)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                return [new SeatClaim('modul', $this->pool)];
            }

            public function pools(Event $event): array
            {
                return [new PoolEntry('modul', 'a', $this->pool)];
            }
        };
        $submit = function () use ($source): SubmitResult {
            $form = $this->readForm($this->form);

            return (new RegistrationSubmit($this->runner, seatClaimSources: [$source]))->submit(
                new SubmitRequest($form->id, $form->version, self::ANSWERS, SubmitToken::generate()),
                new GuestActor()
            );
        };

        $first = $submit();
        self::assertTrue($this->seats->holds($pool, $first->registration));

        try {
            $submit();
            self::fail('The source\'s full pool should have refused the submit.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['modul' => 'Ez a lehetőség időközben betelt. Válassz másikat.'], $invalid->fields);
        }
        self::assertSame(1, $this->registrationCount());
    }
}
