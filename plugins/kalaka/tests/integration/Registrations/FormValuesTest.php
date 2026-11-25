<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\EventFormHost;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationNotActive;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Foundation\TestClock;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * CF2: the values only a form's conditions read (`event.seats_left`, `event.points.<id>`), an additional form's reads
 * of the registration (`registration.status` and the stored values of fields the form does not hold), the submit's
 * walk judging them as they are then, and the registration lock's re-read of what the walk read.
 */
final class FormValuesTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'szint' => 'halado'];

    private int $event;

    private int $slot;

    private RegistrationKey $registration;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        (new FormsModule())->registerMeta();

        $this->event = $this->create();
        $this->update($this->event, [
            'capacity' => 2,
            'points' => [['name' => 'Befizetés', 'at' => '2026-12-20 10:00']],
        ]);
        $this->transition(new PublishEvent(), $this->event);
        $this->slot = $this->insertForm(
            $this->slotFields() . $this->field('choice', 'szint', ['options' => [
                ['value' => 'halado', 'label' => 'Haladó'],
                ['value' => 'kezdo', 'label' => 'Kezdő'],
            ]]),
            ['kalaka_event_id' => $this->event]
        );
        $this->registration = $this->register(self::ANSWERS);
        ActiveRegistrationState::set($this->registration, false);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's test case names this hook
    public function tear_down(): void
    {
        ActiveRegistrationState::set(null, false);
        parent::tear_down();
    }

    private function submitter(): RegistrationSubmit
    {
        return new RegistrationSubmit($this->runner);
    }

    private function host(): EventFormHost
    {
        return new EventFormHost(registrationSubmit: $this->submitter());
    }

    /** @param array<string, mixed> $answers */
    private function register(array $answers): RegistrationKey
    {
        return $this->submitter()->submit(
            new SubmitRequest(
                $this->slot,
                $this->readForm($this->slot)->version,
                $answers,
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;
    }

    /** @param array<string, mixed> $meta */
    private function additional(string $content, array $meta = []): int
    {
        return $this->insertForm(
            $content,
            $meta + ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional']
        );
    }

    /** @param array<string, mixed> $answers */
    private function send(int $form, array $answers, ?RegistrationSubmit $submitter = null): array
    {
        $host = $submitter === null ? $this->host() : new EventFormHost(registrationSubmit: $submitter);

        return $host->submit(
            $form,
            $this->readForm($form)->version,
            $answers,
            SubmitToken::generate(),
            [],
            false,
            new GuestActor()
        );
    }

    /** A branch on one condition, holding the given blocks. */
    private function branch(string $key, string $operator, ?string $value, string $inner): string
    {
        $item = ['key' => $key, 'operator' => $operator] + ($value === null ? [] : ['value' => $value]);

        return '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => ['match' => 'all', 'items' => [$item]]])
            . ' -->' . $inner . '<!-- /wp:kalaka/if -->';
    }

    private function now(string $utc): void
    {
        $this->clock = new TestClock(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
        $this->useClock($this->clock);
        Runner::publish($this->runner);
    }

    private function setStatus(string $status): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => $status],
            ['event_id' => $this->registration->eventId, 'code' => $this->registration->code]
        );
    }

    private function setValue(string $key, string $value): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_field_values',
            ['value' => $value],
            ['event_id' => $this->registration->eventId, 'code' => $this->registration->code, 'field_key' => $key]
        );
    }

    private function formOf(int $id): Form
    {
        return $this->readForm($id);
    }

    private function facts(int $formId): \Kalaka\Forms\Api\ValueContext
    {
        $facts = $this->host()->facts($this->formOf($formId), $this->runner->clock()->now());
        self::assertNotNull($facts);

        return $facts;
    }

    private function render(int $formId): string
    {
        return render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $formId],
            'innerBlocks' => [],
            'innerContent' => [],
            'innerHTML' => '',
        ]);
    }

    public function testSeatsLeftIsTheCapacityLessTheRegisteredAndZeroWhileFull(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        self::assertSame('1', $this->facts($form)->get('event.seats_left')->data);

        $this->register(['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'kezdo']);
        self::assertSame('0', $this->facts($form)->get('event.seats_left')->data);

        // A waitlisted registration takes no place and the latch keeps it at 0 after a cancellation.
        $this->setStatus('withdrawn');
        self::assertSame('1', $this->facts($form)->get('event.seats_left')->data);
        $this->markFull($this->event);
        self::assertSame('0', $this->facts($form)->get('event.seats_left')->data);
    }

    public function testAnEventWithoutACapacityReadsAsEmptyAndAnyPoolDoesNotShowUp(): void
    {
        $unlimited = $this->create('Korlátlan');
        $event = $this->events->find($unlimited);
        self::assertNotNull($event);
        $form = new Form(1, $unlimited, FormPlacement::Registration, null, false, 1);

        $facts = $this->host()->factsFor($form, $event, null, $this->clock->now(), null);

        self::assertTrue($facts->get('event.seats_left')->isEmpty());
        self::assertSame(ValueType::Number, $facts->get('event.seats_left')->type);
        foreach (array_keys($this->host()->typesOf($event, FormPlacement::Registration)) as $key) {
            self::assertStringStartsNotWith('seats_left.', $key);
        }
    }

    public function testACustomPointIsADateByItsId(): void
    {
        $form = $this->additional($this->field('text', 'note'));

        $point = $this->facts($form)->get('event.points.1');

        self::assertSame('2026-12-20', $point->data);
        self::assertSame(ValueType::Date, $point->type);
    }

    public function testEveryDeclaredKeyHasAValueForBothPlacementsAndWithoutAnActiveRegistration(): void
    {
        $registration = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $additional = $this->additional($this->field('text', 'note'));
        $host = $this->host();

        $check = function (int $formId) use ($host): void {
            $form = $this->formOf($formId);
            $facts = $host->facts($form, $this->runner->clock()->now());
            self::assertNotNull($facts);
            foreach (array_keys($host->factTypes($form)) as $key) {
                self::assertTrue($facts->has((string) $key), 'No value for ' . $key);
            }
        };
        $check($registration);
        $check($additional);
        ActiveRegistrationState::set(null, false);
        $check($additional);

        // The registration keys are the additional form's alone.
        self::assertArrayNotHasKey('registration.status', $host->factTypes($this->formOf($registration)));
        self::assertArrayNotHasKey('szint', $host->factTypes($this->formOf($registration)));
        self::assertSame(ValueType::Choice, $host->factTypes($this->formOf($additional))['szint']);
        self::assertTrue($this->facts($additional)->get('registration.status')->isEmpty());
    }

    public function testAnAdditionalFormsBranchReadsAStoredValueOfAFieldItDoesNotHold(): void
    {
        $form = $this->additional(
            $this->field('text', 'note')
            . $this->branch('szint', 'equals', 'halado', $this->field('text', 'extra', ['required' => true]))
        );

        // The registration chose `halado` at registration: the branch shows and `extra` is required.
        try {
            $this->send($form, ['note' => 'x']);
            self::fail('The required field of the shown branch was not refused.');
        } catch (InvalidAnswers $refused) {
            self::assertArrayHasKey('extra', $refused->fields);
        }

        $this->setValue('szint', 'kezdo');
        $answer = $this->send($form, ['note' => 'x']);
        self::assertSame('registered', $answer['status']);
        self::assertSame('x', (new Registrations())->values($this->registration)['note']);
    }

    public function testAFieldTheFormHoldsIsReadFromItsAnswersNotItsStoredValue(): void
    {
        // `szint` is a stored value `halado`, but this form holds `szint` itself: the branch follows the answer.
        $form = $this->additional(
            $this->field('choice', 'szint', ['options' => [
                ['value' => 'halado', 'label' => 'Haladó'],
                ['value' => 'kezdo', 'label' => 'Kezdő'],
            ]])
            . $this->branch('szint', 'equals', 'halado', $this->field('text', 'extra', ['required' => true]))
        );

        $answer = $this->send($form, ['szint' => 'kezdo']);

        self::assertSame('registered', $answer['status']);
        self::assertSame('kezdo', (new Registrations())->values($this->registration)['szint']);
    }

    public function testARegistrationStatusIsReadByAnAdditionalFormsCondition(): void
    {
        $form = $this->additional(
            $this->field('text', 'note')
            . $this->branch('registration.status', 'equals', 'registered', $this->field('text', 'extra', [
                'required' => true,
            ]))
        );

        try {
            $this->send($form, ['note' => 'x']);
            self::fail('The required field of the branch for a registered registration was not refused.');
        } catch (InvalidAnswers $refused) {
            self::assertArrayHasKey('extra', $refused->fields);
        }
        self::assertSame('registered', $this->send($form, ['note' => 'x', 'extra' => 'y'])['status']);

        // A waitlisted registration has no additional forms (owner ruling 2026-11-25), whatever the branch reads.
        $this->setStatus('waitlisted');
        $this->expectException(RegistrationNotActive::class);
        $this->send($form, ['note' => 'x', 'extra' => 'y']);
    }

    public function testABranchOnAVolatileKeyIsNeverSentToTheBrowserUnlessItHoldsAndSeatsLeftIsOneOfThem(): void
    {
        $registration = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.seats_left', 'greaterThan', '5', $this->field('text', 'titkos'))
            . $this->branch('event.seats_left', 'greaterThan', '0', $this->field('text', 'nyitott')),
            ['kalaka_event_id' => $this->event]
        );
        $additional = $this->additional(
            $this->field('text', 'note')
            . $this->branch('szint', 'equals', 'kezdo', $this->field('text', 'tarolt'))
            . $this->branch('szint', 'equals', 'halado', $this->field('text', 'hozzajut'))
        );

        $html = $this->render($registration);
        self::assertStringNotContainsString('titkos', $html);
        self::assertStringContainsString('nyitott', $html);
        $page = $this->render($additional);
        self::assertStringNotContainsString('tarolt', $page);
        self::assertStringContainsString('hozzajut', $page);

        $definition = $this->forms->definition($this->formOf($registration));
        self::assertCount(2, $definition->withheldChains());
    }

    public function testAWithheldBranchThatFlipsToShownRefusesItsMissingRequiredField(): void
    {
        // The last place goes between the page and the submit: the branch on no places left was withheld on the page.
        $form = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.seats_left', 'lessThan', '1', $this->field('text', 'indok', [
                'required' => true,
            ])),
            ['kalaka_event_id' => $this->event]
        );
        self::assertStringNotContainsString('indok', $this->render($form));

        $this->register(['name' => 'Teszt Cili', 'email' => 'teszt.cili@example.org', 'szint' => 'kezdo']);
        $send = fn (array $answers) => $this->submitter()->submit(
            new SubmitRequest($form, $this->readForm($form)->version, $answers, SubmitToken::generate()),
            new GuestActor()
        );
        try {
            $send(['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado']);
            self::fail('The required field of the branch that now shows was not refused.');
        } catch (InvalidAnswers $refused) {
            self::assertArrayHasKey('indok', $refused->fields);
        }
        $registered = $send([
            'name' => 'Teszt Béla',
            'email' => 'teszt.bela@example.org',
            'szint' => 'halado',
            'indok' => 'van',
        ]);
        self::assertContains($registered->status->value, ['registered', 'waitlisted']);
    }

    /**
     * A clock that runs a callback once, on its first reading: the first reading in a submit comes right after
     * the walk read the registration (`RegistrationSubmit`), so what it writes is a change between that read
     * and the lock.
     *
     * @param \Closure(): void $change
     * @return Clock&object{calls: int}
     */
    private function changingClock(\Closure $change, bool $always = false): Clock
    {
        $inner = $this->clock;

        return new class ($inner, $change, $always) implements Clock {
            public int $calls = 0;

            public function __construct(
                private readonly TestClock $inner,
                private readonly \Closure $change,
                private readonly bool $always,
            ) {
            }

            public function now(): \DateTimeImmutable
            {
                if ($this->calls++ === 0 || $this->always) {
                    ($this->change)();
                }

                return $this->inner->now();
            }
        };
    }

    public function testAStatusChangedToWaitlistedBetweenTheWalkAndTheLockRefusesTheSubmitAndWritesNothing(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $clock = $this->changingClock(fn () => $this->setStatus('waitlisted'));
        $submitter = new RegistrationSubmit(new Runner($clock, true));

        // The walk read `registered`; under the lock the status is `waitlisted`: no additional forms for it.
        try {
            $this->send($form, ['note' => 'x'], $submitter);
            self::fail('A waitlisted registration submitted an additional form.');
        } catch (RegistrationNotActive) {
            $this->addToAssertionCount(1);
        }
        self::assertArrayNotHasKey('note', (new Registrations())->values($this->registration));
    }

    public function testAStoredValueChangedBetweenTheWalkAndTheLockRunsTheWalkAgain(): void
    {
        $form = $this->additional(
            $this->field('text', 'note')
            . $this->branch('szint', 'equals', 'kezdo', $this->field('text', 'extra', ['required' => true]))
        );
        $clock = $this->changingClock(fn () => $this->setValue('szint', 'kezdo'));
        $submitter = new RegistrationSubmit(new Runner($clock, true));

        try {
            $this->send($form, ['note' => 'x'], $submitter);
            self::fail('The second walk did not read the changed value.');
        } catch (InvalidAnswers $refused) {
            self::assertArrayHasKey('extra', $refused->fields);
        }
    }

    public function testAnUnchangedReadSubmitsWithoutARetry(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $clock = $this->changingClock(static function (): void {
        });
        $submitter = new RegistrationSubmit(new Runner($clock, true));

        $this->send($form, ['note' => 'x'], $submitter);

        self::assertSame('x', (new Registrations())->values($this->registration)['note']);
    }

    public function testAChangeThatKeepsHappeningEndsInTheConflictAfterTheAttempts(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $count = 0;
        $clock = $this->changingClock(function () use (&$count): void {
            $this->setValue('szint', 'v' . ++$count);
        }, true);
        $submitter = new RegistrationSubmit(new Runner($clock, true));

        try {
            $this->send($form, ['note' => 'x'], $submitter);
            self::fail('A change at every attempt did not end in a conflict.');
        } catch (VersionConflict) {
            self::assertGreaterThanOrEqual(RegistrationSubmit::ATTEMPTS, $count);
        }
        self::assertArrayNotHasKey('note', (new Registrations())->values($this->registration));
    }

    public function testAResendAfterTheLastSeatWentIsAnsweredAsARepeat(): void
    {
        // A branch on the places left: A sees 1 place left and answers the branch; once A is in, 0 are left,
        // and the same resend (same token, same answers) must still be a repeat, not a refusal.
        $form = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.seats_left', 'greaterThan', '0', $this->field('text', 'bonusz', [
                'required' => true,
            ])),
            ['kalaka_event_id' => $this->event]
        );
        $answers = ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado', 'bonusz' => 'x'];
        $token = SubmitToken::generate();
        $request = new SubmitRequest($form, $this->readForm($form)->version, $answers, $token);

        $first = $this->submitter()->submit($request, new GuestActor());
        self::assertFalse($first->repeated);
        $probe = $this->additional($this->field('text', 'note'));
        self::assertSame('0', $this->facts($probe)->get('event.seats_left')->data);

        $again = $this->submitter()->submit($request, new GuestActor());

        self::assertTrue($again->repeated);
        self::assertSame($first->registration->code, $again->registration->code);
        // The same token with other answers is still refused.
        $this->expectException(\Kalaka\Registrations\Api\SubmitTokenReused::class);
        $this->submitter()->submit(
            new SubmitRequest($form, $this->readForm($form)->version, ['name' => 'Teszt Cili'] + $answers, $token),
            new GuestActor()
        );
    }

    /** @return array{int, string} the point's ID and the content of a registration form reading it */
    private function pointForm(): array
    {
        // Half past midnight in the site's zone (Europe/Budapest): the 19th in UTC, the 20th for the site.
        $this->update($this->event, ['points' => [['name' => 'Határidő', 'at' => '2026-12-20 00:30']]]);
        $event = $this->events->find($this->event);
        self::assertNotNull($event);
        $id = $event->customPoints[0]->id;
        $content = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.points.' . $id, 'equals', '2026-12-20', $this->field('text', 'pont', [
                'required' => true,
            ]));

        return [$id, $content];
    }

    public function testAConditionOnACustomPointParsesAndIsEvaluatedOnTheSiteDay(): void
    {
        [, $content] = $this->pointForm();
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);
        $send = fn (array $extra) => $this->submitter()->submit(
            new SubmitRequest(
                $form,
                $this->readForm($form)->version,
                ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado'] + $extra,
                SubmitToken::generate()
            ),
            new GuestActor()
        );

        // The point is the 20th on the site (not the 19th as in UTC), so the branch shows and `pont` is required.
        try {
            $send([]);
            self::fail('The branch on the point did not show.');
        } catch (InvalidAnswers $refused) {
            self::assertArrayHasKey('pont', $refused->fields);
        }
        self::assertSame('registered', $send(['pont' => 'x'])->status->value);
        self::assertNull(DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event));
    }

    public function testAPointRemovedAfterPublishingReadsAsEmptyAndTheFormStaysOpenButASaveIsRefused(): void
    {
        [$id, $content] = $this->pointForm();
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);

        $this->update($this->event, ['points' => []]);

        // The form still parses; the key is empty, so the branch does not hold and nothing is required.
        $definition = $this->forms->definition($this->readForm($form));
        $gone = $this->host()->goneTypes($this->readForm($form), ['event.points.' . $id, 'event.points.x']);
        self::assertSame(['event.points.' . $id => ValueType::Date], $gone);
        $facts = $this->host()->facts($this->readForm($form), $this->runner->clock()->now());
        self::assertNotNull($facts);
        self::assertTrue($facts->get('event.points.' . $id)->isEmpty());
        self::assertCount(1, $definition->chains);
        $registered = $this->submitter()->submit(
            new SubmitRequest(
                $form,
                $this->readForm($form)->version,
                ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado'],
                SubmitToken::generate()
            ),
            new GuestActor()
        );
        self::assertSame('registered', $registered->status->value);
        // Saving a form that reads a point the event does not have is refused.
        self::assertSame(
            ['unknown_point', 'event.points.' . $id],
            DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event)
        );
    }

    public function testTheEditorsKeyListIsThePerEventListOfTheEditedFormsPlacement(): void
    {
        $registration = $this->insertForm($this->slotFields(), ['kalaka_event_id' => $this->event]);
        $additional = $this->additional($this->field('text', 'note'));
        $types = new \ReflectionMethod(FormsModule::class, 'editedFormTypes');

        $GLOBALS['post'] = get_post($additional);
        $forAdditional = $types->invoke(null);
        $GLOBALS['post'] = get_post($registration);
        $forRegistration = $types->invoke(null);
        unset($GLOBALS['post']);

        foreach (['event.seats_left', 'event.points.1', 'event.start'] as $key) {
            self::assertArrayHasKey($key, $forAdditional);
            self::assertArrayHasKey($key, $forRegistration);
        }
        self::assertSame(ValueType::Choice, $forAdditional['registration.status']);
        self::assertSame(ValueType::Choice, $forAdditional['szint']);
        self::assertArrayNotHasKey('registration.status', $forRegistration);
        self::assertArrayNotHasKey('szint', $forRegistration);
        // What the editor prints is the value types' names.
        self::assertSame('number', FormsModule::editorData($forAdditional)['eventTypes']['event.seats_left']);
    }

    public function testTheReFetchFlipsAWithheldBranchWhenItsValueChanges(): void
    {
        $form = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.seats_left', 'lessThan', '1', $this->field('text', 'indok')),
            ['kalaka_event_id' => $this->event]
        );
        $GLOBALS['wp_rest_server'] = null;
        $refetch = function () use ($form): array {
            $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $form . '/availability');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode([
                'version' => $this->readForm($form)->version,
                'answers' => new \stdClass(),
            ]));
            $data = rest_do_request($request)->get_data();
            self::assertIsArray($data);

            return $data;
        };

        self::assertFalse($refetch()['availability']['fields']['indok']);
        $this->register(['name' => 'Teszt Cili', 'email' => 'teszt.cili@example.org', 'szint' => 'kezdo']);
        self::assertTrue($refetch()['availability']['fields']['indok']);
        self::assertNotEmpty($this->forms->definition($this->readForm($form))->withheldChains());
    }

    public function testAnOlderRevisionThatReadsARemovedPointStillParsesAndItsRegistrantsMailRecipientResolves(): void
    {
        [, $content] = $this->pointForm();
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);
        $older = $this->readForm($form)->version;
        $key = $this->submitter()->submit(
            new SubmitRequest(
                $form,
                $older,
                ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado', 'pont' => 'x'],
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;

        // The form is saved again without the condition, and the event drops the point: the registration's
        // own (older) revision reads a point that is gone.
        wp_update_post(['ID' => $form, 'post_content' => $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])]);
        $this->update($this->event, ['points' => []]);
        self::assertGreaterThan($older, $this->readForm($form)->version);

        $definition = $this->forms->definition($this->readForm($form), $older);
        self::assertCount(1, $definition->chains);
        self::assertSame('teszt.bela@example.org', (new MailValues())->recipient($key));
    }

    public function testTextThatMentionsAPointKeyDeclaresNothing(): void
    {
        $content = $this->slotFields() . '<!-- wp:paragraph --><p>event.points.5</p><!-- /wp:paragraph -->';
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);

        self::assertArrayNotHasKey('event.points.5', $this->host()->factTypes($this->readForm($form)));
        self::assertTrue($this->facts($form)->has('event.start'));
        self::assertFalse($this->facts($form)->has('event.points.5'));
        self::assertNull(DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event));
    }

    public function testAResendWhoseWalkNowFailsIsStillARepeatAndNewAnswersStillFail(): void
    {
        // With places left there is nothing more to answer; once none are left an else branch requires a box.
        $form = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('event.seats_left', 'greaterThan', '0', $this->field('text', 'bonusz'))
            . '<!-- wp:kalaka/else -->' . $this->field('text', 'elfogad', ['required' => true])
            . '<!-- /wp:kalaka/else -->',
            ['kalaka_event_id' => $this->event]
        );
        $answers = ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado'];
        $token = SubmitToken::generate();
        $request = new SubmitRequest($form, $this->readForm($form)->version, $answers, $token);
        $first = $this->submitter()->submit($request, new GuestActor());

        // Now the else branch shows and `elfogad` is required: a fresh submit fails, the resend is a repeat.
        $again = $this->submitter()->submit($request, new GuestActor());
        self::assertTrue($again->repeated);
        self::assertSame($first->registration->code, $again->registration->code);
        $this->expectException(InvalidAnswers::class);
        $this->submitter()->submit(
            new SubmitRequest($form, $this->readForm($form)->version, $answers, SubmitToken::generate()),
            new GuestActor()
        );
    }

    public function testAFailedWalkOnFactsThatMovedIsRunAgainNotSaid(): void
    {
        // The walk reads `halado` and fails (`extra` is required); a staff write flips the value before the lock,
        // so `extra` is hidden now: the submit is run again and succeeds, instead of saying the first failure.
        $form = $this->additional(
            $this->field('text', 'note')
            . $this->branch('szint', 'equals', 'halado', $this->field('text', 'extra', ['required' => true]))
        );
        $clock = $this->changingClock(fn () => $this->setValue('szint', 'kezdo'));
        $submitter = new RegistrationSubmit(new Runner($clock, true));

        $answer = $this->send($form, ['note' => 'x'], $submitter);

        self::assertSame('registered', $answer['status']);
        self::assertSame('x', (new Registrations())->values($this->registration)['note']);
    }

    public function testARevisionThatReadsTheDroppedChoiceOpenKeyStillParsesAndReadsEmpty(): void
    {
        // Owner ruling 2026-11-25: a set has no choice window. A revision stored before it may still read
        // `choice_open.<field key>`: the key is gone, reads as empty, and the branch never holds.
        $form = $this->insertForm(
            $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('choice_open.modulok', 'equals', 'yes', $this->field('text', 'indok', [
                'required' => true,
            ])),
            ['kalaka_event_id' => $this->event]
        );
        $current = $this->readForm($form);

        self::assertSame(
            ['choice_open.modulok' => ValueType::Choice],
            $this->host()->goneTypes($current, ['choice_open.modulok', 'choice_open_x', 'event.points.x'])
        );
        self::assertCount(1, $this->forms->definition($current)->chains);
        $facts = $this->host()->facts($current, $this->clock->now());
        self::assertNotNull($facts);
        self::assertTrue($facts->get('choice_open.modulok')->isEmpty());
        self::assertSame(ValueType::Choice, $facts->get('choice_open.modulok')->type);
        self::assertArrayNotHasKey(
            'choice_open.modulok',
            $this->host()->typesOf(
                $this->events->find($this->event) ?? self::fail('No event.'),
                FormPlacement::Registration
            )
        );
        $registered = $this->submitter()->submit(
            new SubmitRequest(
                $form,
                $current->version,
                ['name' => 'Teszt Béla', 'email' => 'teszt.bela@example.org', 'szint' => 'halado'],
                SubmitToken::generate()
            ),
            new GuestActor()
        );
        self::assertSame('registered', $registered->status->value);
        // A save refuses it like any key nothing declares.
        self::assertNotNull(DefinitionCheck::problem(
            (string) get_post($form)?->post_content,
            $form,
            FormPlacement::Registration,
            $this->event
        ));
    }

    public function testTheGonePointsAreThoseOfTheRevisionBeingWalkedNotTheCurrentContent(): void
    {
        $this->update($this->event, ['points' => [
            ['id' => 1, 'name' => 'Befizetés', 'at' => '2026-12-20 10:00'],
            ['name' => 'Másik', 'at' => '2026-12-22 10:00'],
        ]]);
        $inner = $this->field('text', 'pont', ['required' => true]);
        $base = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]]);
        $form = $this->insertForm(
            $base . $this->branch('event.points.1', 'equals', '2026-12-20', $inner),
            ['kalaka_event_id' => $this->event]
        );
        $older = $this->readForm($form)->version;
        wp_update_post([
            'ID' => $form,
            'post_content' => $base . $this->branch('event.points.2', 'equals', '2026-12-22', $inner),
        ]);
        $this->update($this->event, ['points' => [['id' => 2, 'name' => 'Másik', 'at' => '2026-12-22 10:00']]]);
        $current = $this->readForm($form);
        self::assertGreaterThan($older, $current->version);
        $event = $this->events->find($this->event);
        self::assertNotNull($event);
        $facts = fn (int $version) => $this->host()->factsFor(
            new Form($form, $this->event, FormPlacement::Registration, null, false, $version),
            $event,
            null,
            $this->clock->now(),
            null,
            null
        );

        // The older revision reads point 1, which is gone: valued none. The current one reads point 2, which
        // the event still has: no point is gone, so none is added (and point 1 is no key of its facts).
        self::assertTrue($facts($older)->get('event.points.1')->isEmpty());
        self::assertFalse($facts($current->version)->has('event.points.1'));
        self::assertSame('2026-12-22', $facts($current->version)->get('event.points.2')->data);
    }
    /**
     * An assignment set tied to the request field `modulok`, with the options `modul_a` (a limit of 2) and
     * `modul_b` (no limit).
     */
    private function modulSet(): int
    {
        $sets = new AssignmentSets($this->runner);
        $set = $sets->save([
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => false,
            'published' => false,
        ], new CliActor(null));
        $sets->tieRequestField($set, 'modulok', new CliActor(null));
        foreach (['Modul A' => 2, 'Modul B' => null] as $label => $limit) {
            $sets->saveOption([
                'set_id' => $set,
                'label' => $label,
                'limit' => $limit,
                'leaders' => '',
                'place' => '',
                'position' => 0,
            ], new CliActor(null));
        }

        return $set;
    }

    public function testEverySetOptionsPlacesLeftIsAFormVariableAndAnUnlimitedOneIsNone(): void
    {
        $set = $this->modulSet();
        $registration = $this->slot;
        $additional = $this->additional($this->field('text', 'note'));

        foreach ([$registration, $additional] as $form) {
            $types = $this->host()->factTypes($this->formOf($form));
            self::assertSame(ValueType::Number, $types['seats_left.modulok.modul_a']);
            self::assertSame(ValueType::Number, $types['seats_left.modulok.modul_b']);
        }
        $facts = $this->facts($additional);
        self::assertSame('2', $facts->get('seats_left.modulok.modul_a')->data);
        self::assertTrue($facts->get('seats_left.modulok.modul_b')->isEmpty());
        self::assertSame(ValueType::Number, $facts->get('seats_left.modulok.modul_b')->type);

        $this->runner->run(new PlaceRegistration(), [
            'event_id' => $this->registration->eventId,
            'code' => $this->registration->code,
            'set_id' => $set,
            'option' => 'modul_a',
        ], new CliActor(null));
        self::assertSame('1', $this->facts($additional)->get('seats_left.modulok.modul_a')->data);
    }

    /**
     * Runs the body with one more contributed seat claim source, as an extension's tiers or offers would be.
     *
     * @param \Closure(): void $body
     */
    private function withSource(SeatClaimSource $source, \Closure $body): void
    {
        $before = Contributions::current();
        $with = clone $before;
        $with->seatClaimSource($source);
        Contributions::record($with);
        try {
            $body();
        } finally {
            Contributions::record($before);
        }
    }

    private function listing(PoolEntry ...$entries): SeatClaimSource
    {
        return new class ($entries) implements SeatClaimSource {
            /** @param list<PoolEntry> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                return [];
            }

            public function pools(Event $event): array
            {
                return $this->entries;
            }
        };
    }

    public function testAnExtensionsPoolIsServedWithNoCodeHereAndARemovedOneReadsZero(): void
    {
        $this->modulSet();
        $pool = new TestQuotaPool($this->event, 'tier.1', 'kalaka_test_items', 1, 3);
        $source = $this->listing(new PoolEntry('price_tier', 'felnott', $pool));
        $content = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('seats_left.price_tier.felnott', 'greaterThan', '0', $this->field('text', 'extra'));
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);

        $this->withSource($source, function () use ($form, $content): void {
            self::assertSame(
                ValueType::Number,
                $this->host()->factTypes($this->formOf($form))['seats_left.price_tier.felnott']
            );
            self::assertSame('3', $this->facts($form)->get('seats_left.price_tier.felnott')->data);
            // The save accepts a key that is served now.
            self::assertNull(DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event));
        });

        // The source is gone (the tier was removed): the stored revision still parses, and the pool reads 0,
        // never none, so "more than 0" never reads it as open. A save refuses the key.
        $facts = $this->facts($form);
        self::assertSame('0', $facts->get('seats_left.price_tier.felnott')->data);
        self::assertSame(ValueType::Number, $facts->get('seats_left.price_tier.felnott')->type);
        self::assertSame(
            ['condition_key_not_earlier', 'seats_left.price_tier.felnott'],
            DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event)
        );
        self::assertSame(
            ['seats_left.price_tier.felnott' => ValueType::Number],
            $this->host()->goneTypes($this->formOf($form), ['seats_left.price_tier.felnott', 'szint', 'seats_left'])
        );
    }

    public function testEveryTimeValueIsReadAtTheMomentTheFactsAreBuiltFor(): void
    {
        $this->now('2026-11-30 08:00:00');
        $form = $this->additional($this->field('text', 'note'));
        $event = $this->events->find($this->event);
        self::assertNotNull($event);

        $facts = $this->host()->factsFor(
            $this->formOf($form),
            $event,
            null,
            new \DateTimeImmutable('2026-12-05 09:00:00', new \DateTimeZone('UTC')),
            null
        );

        self::assertSame('2026-12-05', $facts->get(Facts::NOW)->data);
    }

    public function testAnAdditionalFormReadsEveryRegistrationKeyTheOneBuilderDeclaresAndNotTheCode(): void
    {
        $event = $this->events->find($this->event);
        self::assertNotNull($event);
        $form = $this->additional($this->field('text', 'note'));
        $builder = (new Registrations())->factsOf($this->registration, $event, $this->runner->clock()->now());
        self::assertNotNull($builder);
        $types = $this->host()->factTypes($this->formOf($form));
        $facts = $this->facts($form);

        self::assertArrayNotHasKey(RegistrationFacts::CODE, $types);
        self::assertNotSame([], RegistrationFacts::formTypes($event));
        foreach (RegistrationFacts::formTypes($event) as $key => $type) {
            self::assertSame($type, $types[$key]);
            self::assertEquals($builder->get($key), $facts->get($key), $key);
        }
        self::assertSame(['registered'], $facts->get('registration.status')->data);
    }

    public function testAPoolsPlacesMovingBetweenTheWalkAndTheLockIsNoChangedRead(): void
    {
        $set = $this->modulSet();
        $form = $this->additional($this->field('text', 'note'));
        $pool = new \Kalaka\Assignments\OptionSeatPool($this->event, $set, 'modul_a');
        $seats = new \Kalaka\Registrations\Api\Seats();
        $other = $this->register(['name' => 'Teszt Cili', 'email' => 'teszt.cili@example.org', 'szint' => 'kezdo']);

        // One attempt without any change: the clock is read this many times.
        $idle = $this->changingClock(static function (): void {
        });
        $this->send($form, ['note' => 'x'], new RegistrationSubmit(new Runner($idle, true)));
        $single = $idle->calls;

        // The pool's places move after the walk read them; a changed read would run the submit again and
        // read the clock again.
        $clock = $this->changingClock(function () use ($set, $other): void {
            $this->runner->run(new PlaceRegistration(), [
                'event_id' => $other->eventId,
                'code' => $other->code,
                'set_id' => $set,
                'option' => 'modul_a',
            ], new CliActor(null));
        });
        $this->send($form, ['note' => 'y'], new RegistrationSubmit(new Runner($clock, true)));

        self::assertTrue($seats->holds($pool, $other), 'The pool did move.');
        self::assertSame($single, $clock->calls, 'One attempt only.');
        self::assertSame('y', (new Registrations())->values($this->registration)['note']);
    }

    /** A source whose listing, or whose pool, fails. */
    private function failing(bool $listing, ?PoolEntry $entry = null): SeatClaimSource
    {
        return new class ($listing, $entry) implements SeatClaimSource {
            public function __construct(private readonly bool $listing, private readonly ?PoolEntry $entry)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                return [];
            }

            public function pools(Event $event): array
            {
                if ($this->listing) {
                    throw new \RuntimeException('A listing that fails.');
                }

                return $this->entry === null ? [] : [$this->entry];
            }
        };
    }

    public function testASourceThatThrowsIsSkippedAndNoFormBreaks(): void
    {
        $this->modulSet();
        $content = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('seats_left.price_tier.felnott', 'greaterThan', '0', $this->field('text', 'extra'));
        $other = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]]);
        $reading = $this->insertForm($content, ['kalaka_event_id' => $this->event]);
        $additional = $this->additional($this->field('text', 'note'));
        $pool = new TestQuotaPool($this->event, 'tier.1', 'kalaka_test_items', 1, 3);
        $dead = new TestQuotaPool($this->event, 'tier.2', 'kalaka_test_items', 2, null);
        $throwing = new class ($this->event) implements \Kalaka\Registrations\Api\SeatPool {
            public function __construct(private readonly int $event)
            {
            }

            public function eventId(): int
            {
                return $this->event;
            }

            public function key(): string
            {
                return 'tier.3';
            }

            public function lockLevel(): \Kalaka\Foundation\Api\UseCase\LockLevel
            {
                return \Kalaka\Foundation\Api\UseCase\LockLevel::Quota;
            }

            public function lockRow(): array
            {
                return ['kalaka_test_items', [3]];
            }

            public function limit(): ?int
            {
                throw new \RuntimeException('A pool whose limit cannot be read.');
            }

            public function lock(\Kalaka\Foundation\Api\UseCase\Context $context): ?int
            {
                return null;
            }
        };
        $ok = $this->listing(new PoolEntry('price_tier', 'kulfoldi', $pool), new PoolEntry('Bad Key', 'x', $dead));
        $bad = $this->listing(new PoolEntry('price_tier', 'rossz', $throwing));
        $this->withSource($ok, function () use ($bad, $reading, $additional, $other): void {
            $this->withSource($bad, function () use ($reading, $additional, $other): void {
                $this->withSource($this->failing(true), function () use ($reading, $additional, $other): void {
                    $host = $this->host();
                    $types = $host->factTypes($this->formOf($additional));
                    // The throwing listing is skipped, the malformed entry dropped, the others served.
                    self::assertArrayNotHasKey('seats_left.bad key.x', $types);
                    self::assertSame(ValueType::Number, $types['seats_left.price_tier.kulfoldi']);
                    self::assertSame('3', $this->facts($additional)->get('seats_left.price_tier.kulfoldi')->data);
                    // A pool whose read throws is declared and reads 0, like a removed one.
                    self::assertSame('0', $this->facts($additional)->get('seats_left.price_tier.rossz')->data);
                    // The page, the re-fetch and a save of a form that reads a skipped source's key still work:
                    // a stored revision reads 0.
                    self::assertSame('0', $this->facts($reading)->get('seats_left.price_tier.felnott')->data);
                    self::assertNotSame('', $this->render($reading));
                    self::assertNull(
                        DefinitionCheck::problem($other, $reading, FormPlacement::Registration, $this->event)
                    );
                    // The submit's walk and the registration go through.
                    $registered = $this->submitter()->submit(
                        new SubmitRequest(
                            $reading,
                            $this->readForm($reading)->version,
                            ['name' => 'Teszt Dóra', 'email' => 'teszt.dora@example.org', 'szint' => 'halado'],
                            SubmitToken::generate()
                        ),
                        new GuestActor()
                    );
                    self::assertSame('registered', $registered->status->value);
                });
            });
        });
    }

    public function testAnOptionValueWithAHyphenIsAPoolVariableAndAConditionMayReadIt(): void
    {
        $this->update($this->event, ['capacity' => 10]);
        $pool = new TestQuotaPool($this->event, 'tier.1', 'kalaka_test_items', 1, 3);
        $source = $this->listing(new PoolEntry('price_tier', 'gyerek-5_12', $pool));
        $content = $this->slotFields()
            . $this->field('choice', 'szint', ['options' => [['value' => 'halado', 'label' => 'Haladó']]])
            . $this->branch('seats_left.price_tier.gyerek-5_12', 'greaterThan', '0', $this->field('text', 'extra'));
        $form = $this->insertForm($content, ['kalaka_event_id' => $this->event]);

        $this->withSource($source, function () use ($form, $content): void {
            self::assertSame('3', $this->facts($form)->get('seats_left.price_tier.gyerek-5_12')->data);
            self::assertNull(DefinitionCheck::problem($content, $form, FormPlacement::Registration, $this->event));
            // The condition holds (3 places), so the field it shows is part of the form's walk.
            $this->submitter()->submit(
                new SubmitRequest(
                    $form,
                    $this->readForm($form)->version,
                    ['name' => 'Teszt Éva', 'email' => 'teszt.eva@example.org', 'szint' => 'halado', 'extra' => 'x'],
                    SubmitToken::generate()
                ),
                new GuestActor()
            );
        });
        // With the source gone the stored revision reads 0, so the branch no longer shows.
        self::assertSame('0', $this->facts($form)->get('seats_left.price_tier.gyerek-5_12')->data);
    }
}
