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
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SecretLink;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * A submit that chooses a limited option takes its seat when it becomes `registered` and is refused, with
 * nothing saved, when the pool is full (topic 15, Q2). A waitlisted submit holds no seat and is not refused (A3).
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
            'capacity' => $places,
            'leaders' => '',
            'place' => '',
            'condition' => null,
            'position' => 0,
        ], new CliActor(null));
    }

    private function submit(): SubmitResult
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
            seatClaims: [new SeatClaim('modul', new OptionSeatPool($this->event, $this->set, 'modul_a'))],
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

    public function testAWaitlistedSubmitWithAFullChoiceIsNotRefusedAndHoldsNoSeat(): void
    {
        $this->openEvent(1, 1);
        $first = $this->submit();
        self::assertSame(RegistrationStatus::Registered, $first->status);

        // The event is full, and so is the option: the second lands on the waitlist, not refused (A3).
        $second = $this->submit();

        self::assertSame(RegistrationStatus::Waitlisted, $second->status);
        self::assertFalse($this->seats->holds($this->pool(), $second->registration));
        self::assertSame(1, $this->seats->held($this->pool()));
    }
}
