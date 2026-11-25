<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\OptionSeatPool;
use Kalaka\Events\Api\Event;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\PoolEntry;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\SeatClaims;
use Kalaka\Registrations\Api\SeatsFull;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Assignments\SetOptionClaimSource;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Api\SubmitTokenReused;
use Kalaka\Registrations\Api\ValueLocked;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * S60: an additional form writes a supplementary submission to the active registration of its event. It
 * opens with the current values, writes only the fields that show (a shown blank clears), appends a
 * submission each time, and never creates a registration.
 */
final class AdditionalFormTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    private int $event;

    private RegistrationKey $registration;

    private int $slot;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        (new FormsModule())->registerMeta();

        $this->event = $this->create();
        $this->update($this->event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $this->event);
        $this->slot = $slot = $this->insertForm(
            $this->slotFields() . $this->field('text', 'note') . $this->field('text', 'other')
            . $this->field('text', 'tier'),
            ['kalaka_event_id' => $this->event]
        );
        $this->registration = $this->submitter()->submit(
            new SubmitRequest(
                $slot,
                $this->readForm($slot)->version,
                self::ANSWERS + ['note' => 'első', 'other' => 'marad'],
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;
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

    /** @param array<string, mixed> $meta */
    private function additional(string $content, array $meta = []): int
    {
        return $this->insertForm(
            $content,
            $meta + ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional']
        );
    }

    /**
     * Submits as the form page's route does: through the host, with the active registration.
     *
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function send(int $form, array $answers, ?string $token = null): array
    {
        return $this->host()->submit(
            $form,
            $this->readForm($form)->version,
            $answers,
            $token ?? SubmitToken::generate(),
            [],
            false,
            new GuestActor()
        );
    }

    /** @return array<string, string> */
    private function values(): array
    {
        return (new Registrations())->values($this->registration);
    }

    private function rowCount(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; prefix and constants
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE event_id = %d", $this->event)
        );
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

    private function render(int $formId): string
    {
        return render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $formId],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    public function testASubmitAppendsASubmissionAndTheLatestWriteWins(): void
    {
        $form = $this->additional($this->field('text', 'note'));

        $answer = $this->send($form, ['note' => 'második']);

        self::assertSame(
            [
                'event' => $this->event,
                'code' => $this->registration->code,
                'status' => 'registered',
                'repeated' => false,
                'after_submit' => '<p>' . RegistrationSubmit::DEFAULT_SUPPLEMENT . '</p>',
            ],
            $answer
        );
        self::assertSame('második', $this->values()['note']);
        self::assertSame('marad', $this->values()['other']);
        self::assertSame(1, $this->rowCount('kalaka_registrations'));
        self::assertSame(2, $this->rowCount('kalaka_submissions'));

        $this->send($form, ['note' => 'harmadik']);
        self::assertSame('harmadik', $this->values()['note']);
        self::assertSame(3, $this->rowCount('kalaka_submissions'));
        self::assertCount(2, $this->entriesOf('kalaka-portal/submit-additional-form'));
    }

    public function testAShownFieldLeftBlankClearsItsValueAndAHiddenOneKeepsIt(): void
    {
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => [
            'match' => 'all',
            'items' => [['key' => 'note', 'operator' => 'equals', 'value' => 'mutasd']],
        ]]) . ' -->' . $this->field('text', 'other') . '<!-- /wp:kalaka/if -->';
        $form = $this->additional($this->field('text', 'note') . $branch);

        // `other` sits in a branch that does not hold: whatever is sent for it is dropped, its value kept.
        $this->send($form, ['other' => 'eldobva']);

        self::assertArrayNotHasKey('note', $this->values());
        self::assertSame('marad', $this->values()['other']);
        // The clear is audited like any write; the kept value is not.
        $entries = $this->entriesOf('kalaka-portal/submit-additional-form');
        self::assertCount(1, $entries);
        self::assertContains('field:note', $this->changedFields($entries[0]));
        self::assertNotContains('field:other', $this->changedFields($entries[0]));
    }

    public function testAClearOfAFieldNoFormMayWriteIsRefusedAndItsValueStays(): void
    {
        $form = $this->additional($this->field('text', 'note'));

        // Drift: the field was form-writable when the form was saved, and a later declaration turned it
        // into an extension field forms may not write. A blank would clear it; the store path refuses such
        // a key, and so does the clear path.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_participant_fields',
            ['source' => 'extension', 'form_writable' => 0],
            ['event_id' => $this->event, 'field_key' => 'note']
        );

        try {
            $this->send($form, []);
            self::fail('A clear of a field no form may write went through.');
        } catch (SystemFailure $failure) {
            self::assertStringContainsString('extension_field note', $failure->getMessage());
        }
        self::assertSame('első', $this->values()['note']);
        self::assertSame(1, $this->rowCount('kalaka_submissions'));
    }

    public function testAWaitlistedRegistrationMaySubmitAndAWithdrawnOneMayNot(): void
    {
        $form = $this->additional($this->field('text', 'note'));

        $this->setStatus('waitlisted');
        self::assertSame('waitlisted', $this->send($form, ['note' => 'várólistán'])['status']);

        $this->setStatus('withdrawn');
        try {
            $this->send($form, ['note' => 'visszalépve']);
            self::fail('A withdrawn registration submitted an additional form.');
        } catch (FormRefused $e) {
            self::assertSame([AccessRefusal::RegistrationMayNotSubmit], $e->refusals);
        }
        self::assertSame('várólistán', $this->values()['note']);
    }

    public function testWithoutAnActiveRegistrationOfTheEventNothingIsWritten(): void
    {
        $form = $this->additional($this->field('text', 'note'));

        foreach ([null, new RegistrationKey($this->event + 1000, $this->registration->code)] as $active) {
            ActiveRegistrationState::set($active, false);
            try {
                $this->send($form, ['note' => 'senki']);
                self::fail('An additional form was submitted without its event\'s active registration.');
            } catch (FormRefused $e) {
                self::assertSame([AccessRefusal::NoActiveRegistration], $e->refusals);
            }
        }
        self::assertSame('első', $this->values()['note']);
        self::assertSame(1, $this->rowCount('kalaka_submissions'));
    }

    public function testTheSecretLinkActsOnlyOnItsOwnRegistration(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $other = $this->submitter()->submit(
            new SubmitRequest(
                $this->slot,
                $this->readForm($this->slot)->version,
                self::ANSWERS,
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;

        $this->expectException(PermissionDenied::class);
        $this->submitter()->submit(
            new SubmitRequest(
                $form,
                $this->readForm($form)->version,
                ['note' => 'más'],
                SubmitToken::generate(),
                target: $this->registration
            ),
            new SecretLinkActor($other)
        );
    }

    public function testARepeatWritesNothingAndTheSameTokenWithOtherAnswersIsRefused(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        $token = SubmitToken::generate();

        $this->send($form, ['note' => 'egyszer'], $token);
        $repeat = $this->send($form, ['note' => 'egyszer'], $token);

        self::assertTrue($repeat['repeated']);
        self::assertSame(2, $this->rowCount('kalaka_submissions'));

        $this->expectException(SubmitTokenReused::class);
        $this->send($form, ['note' => 'máskor'], $token);
    }

    public function testOutsideItsOpenPeriodTheFormIsRefused(): void
    {
        $form = $this->additional(
            $this->field('text', 'note'),
            ['kalaka_closes' => wp_json_encode(['kind' => 'fixed', 'at' => '2000-01-01 00:00:00'])]
        );

        try {
            $this->send($form, ['note' => 'késő']);
            self::fail('A closed additional form was submitted.');
        } catch (FormRefused $e) {
            self::assertSame([AccessRefusal::OutsideOpenPeriod], $e->refusals);
        }
        self::assertStringContainsString('Ez az űrlap jelenleg nem érhető el.', $this->render($form));
    }

    public function testTheFormOpensWithTheCurrentValues(): void
    {
        $choice = $this->field('choice', 'size', ['options' => [
            ['value' => 's', 'label' => 'Kicsi'],
            ['value' => 'm', 'label' => 'Nagy'],
        ]]);
        $form = $this->additional($this->field('text', 'note') . $choice . $this->field('consent', 'ok'));
        $this->send($form, ['note' => 'első <b>"idézet"</b>', 'size' => 'm', 'ok' => true]);

        $html = $this->render($form);

        self::assertStringContainsString('value="első &lt;b&gt;&quot;idézet&quot;&lt;/b&gt;"', $html);
        self::assertMatchesRegularExpression('/value="m"[^>]* checked/', $html);
        self::assertDoesNotMatchRegularExpression('/value="s"[^>]* checked/', $html);
        self::assertMatchesRegularExpression('/name="ok" value="1"[^>]* checked/', $html);
    }

    public function testAConsentGivenEarlierThatIsUntickedIsOverwrittenWithZeroNeverCleared(): void
    {
        $form = $this->additional($this->field('consent', 'ok'));

        $this->send($form, ['ok' => true]);
        self::assertSame('1', $this->values()['ok'] ?? null);

        // Unticked on the form that showed it ticked: the answer is no, stored as 0, not a deleted row.
        $this->send($form, ['ok' => false]);
        self::assertSame('0', $this->values()['ok'] ?? null);

        // Given again: the latest write wins.
        $this->send($form, ['ok' => true]);
        self::assertSame('1', $this->values()['ok'] ?? null);
    }

    public function testAConsentThatWasNeverShownStoresNothing(): void
    {
        $withConsent = $this->additional($this->field('consent', 'ok'));
        $without = $this->additional($this->field('text', 'note'));

        // A form that does not hold the consent leaves it unset, and a consent never sent is never invented.
        $this->send($without, ['note' => 'másik']);
        self::assertArrayNotHasKey('ok', $this->values());

        // Shown and left unticked at the first time: the answer no, 0 (core's rule for a shown consent).
        $this->send($withConsent, ['ok' => false]);
        self::assertSame('0', $this->values()['ok'] ?? null);
        // Then a form without it still keeps it.
        $this->send($without, ['note' => 'harmadik']);
        self::assertSame('0', $this->values()['ok'] ?? null);
    }

    public function testWithoutAnActiveRegistrationThePageAsksForTheLink(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        ActiveRegistrationState::set(null, false);

        $html = $this->render($form);

        self::assertStringContainsString(
            'Az űrlap kitöltéséhez nyisd meg a jelentkezés után kapott e-mailben lévő linket.',
            $html
        );
        self::assertStringNotContainsString('<form', $html);
    }

    public function testAFieldTypeIsNeverAskedInsideTheTransactionAndTheClearStillWorks(): void
    {
        $guard = new UseCaseGuardedTextFieldType();
        $before = Contributions::current();
        $contributions = new Contributions();
        foreach ($before->fieldTypes() as $name => $type) {
            if ($name !== $guard->blockName()) {
                $contributions->fieldType($type);
            }
        }
        $contributions->fieldType($guard);
        Contributions::record($contributions);
        try {
            $form = $this->additional($this->field('text', 'note'));
            // Opening the form asks the type once; a blank submit asks it before the transaction only.
            $asked = $guard->prefills;
            $this->send($form, []);
            self::assertGreaterThan($asked, $guard->prefills);
        } finally {
            Contributions::record($before);
        }

        self::assertArrayNotHasKey('note', $this->values());
        self::assertSame('marad', $this->values()['other']);
    }

    public function testAfterABadLinkThePageSaysSoInTheStatusBlocksText(): void
    {
        $form = $this->additional($this->field('text', 'note'));
        ActiveRegistrationState::set(null, true);

        $html = $this->render($form);

        self::assertStringContainsString(
            'Ez a link nem érvényes. Nyisd meg a legutóbb kapott e-mailben lévő linket.',
            $html
        );
        self::assertStringNotContainsString('Az űrlap kitöltéséhez nyisd meg', $html);
        self::assertStringNotContainsString('<form', $html);
    }
    /**
     * An extension declares a participant field in a run of its own, as Extras or the price ladder do.
     *
     * @param string $type a block name
     */
    private function declareLocked(string $key, string $type, bool $moneyRelevant): void
    {
        $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($key, $type, $moneyRelevant): void {
                (new ParticipantFields())->declareExtensionField(
                    $context,
                    $this->event,
                    $key,
                    $type,
                    'Cím',
                    null,
                    true,
                    'kalaka-extras',
                    null,
                    $moneyRelevant
                );
            }),
            [],
            new CliActor(null)
        );
    }

    public function testAFieldAnExtensionDeclaredMoneyRelevantIsLockedAfterItsFirstWriteWhateverItsBlock(): void
    {
        $this->declareLocked('ebed', 'kalaka/consent-field', true);
        $this->declareLocked('szabad', 'kalaka/text-field', false);
        $form = $this->additional(
            $this->field('consent', 'ebed') . $this->field('text', 'szabad') . $this->field('text', 'note')
        );

        // A first write passes: the registration form never held the field.
        $this->send($form, ['ebed' => true, 'szabad' => 'egy', 'note' => 'x']);
        self::assertSame('1', $this->values()['ebed']);

        // The declared money field keeps it, ticked or unticked; the unflagged field and a form field change.
        try {
            $this->send($form, ['ebed' => false, 'szabad' => 'kettő', 'note' => 'y']);
            self::fail('A declared money-relevant field was overwritten.');
        } catch (ValueLocked $violation) {
            self::assertSame('kalaka_value_locked', $violation->errorCode());
            self::assertSame('1', $this->values()['ebed']);
        }
        self::assertSame('egy', $this->values()['szabad']);
        $this->send($form, ['ebed' => true, 'szabad' => 'kettő', 'note' => 'y']);
        self::assertSame('kettő', $this->values()['szabad']);
        self::assertSame('y', $this->values()['note']);
    }

    public function testADeclaredFlagAloneLocksATextFieldAndAnUntickedConsentIsAFirstWrite(): void
    {
        $this->declareLocked('adomany', 'kalaka/text-field', true);
        $this->declareLocked('ebed', 'kalaka/consent-field', true);
        $form = $this->additional($this->field('text', 'adomany') . $this->field('consent', 'ebed'));

        $this->send($form, ['adomany' => '1000', 'ebed' => false]);
        self::assertSame('0', $this->values()['ebed']);

        // The `0` was the first write: ticking later is a change, and so is a different amount.
        foreach ([['adomany' => '1000', 'ebed' => true], ['adomany' => '2000', 'ebed' => false]] as $answers) {
            try {
                $this->send($form, $answers);
                self::fail('A locked value was changed by an additional form.');
            } catch (ValueLocked $violation) {
                self::assertSame('kalaka_value_locked', $violation->errorCode());
                self::assertSame('1000', $this->values()['adomany']);
                self::assertSame('0', $this->values()['ebed']);
            }
        }
        // The same values again are no change.
        $this->send($form, ['adomany' => '1000', 'ebed' => false]);
    }

    /**
     * A seat claim source over a set's options: the field `tier` claims the pool of the option with its value.
     *
     * @return array{int, SeatClaimSource} the set and the source
     */
    private function tierSource(int $limit = 5): array
    {
        $sets = new AssignmentSets($this->runner);
        $set = $sets->save([
            'event_id' => $this->event,
            'name' => 'Szintek',
            'required' => false,
            'published' => false,
        ], new CliActor(null));
        foreach (['A', 'B'] as $label) {
            $sets->saveOption([
                'set_id' => $set,
                'label' => $label,
                'limit' => $limit,
                'leaders' => '',
                'place' => '',
                'position' => 0,
            ], new CliActor(null));
        }
        $source = new class ($this->event, $set) implements SeatClaimSource {
            public function __construct(private readonly int $event, private readonly int $set)
            {
            }

            public function claims(Event $event, ValidatedAnswers $answers): array
            {
                $tier = $answers->stored()['tier'] ?? null;

                return $tier === null || $tier === ''
                    ? []
                    : [new SeatClaim('tier', new OptionSeatPool($this->event, $this->set, $tier))];
            }

            public function pools(Event $event): array
            {
                return [new PoolEntry('tier', 'a', new OptionSeatPool($this->event, $this->set, 'a'))];
            }
        };

        return [$set, $source];
    }

    /** A registration that chose tier `a` and holds its seat, active for the additional forms. */
    private function registerInTierA(
        SeatClaimSource $source,
        string $tier = 'a',
        string $email = 'teszt.bela'
    ): RegistrationKey {
        $answers = ['name' => 'Teszt Béla', 'email' => $email . '@example.org'];
        if ($tier !== '') {
            $answers['tier'] = $tier;
        }
        $key = (new RegistrationSubmit($this->runner, seatClaimSources: [$source]))->submit(
            new SubmitRequest(
                $this->slot,
                $this->readForm($this->slot)->version,
                $answers,
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;
        ActiveRegistrationState::set($key, false);
        $this->registration = $key;

        return $key;
    }

    /** @param array<string, mixed> $answers */
    private function sendWith(SeatClaimSource $source, int $form, array $answers): array
    {
        return (new EventFormHost(registrationSubmit: new RegistrationSubmit(
            $this->runner,
            seatClaimSources: [$source]
        )))->submit(
            $form,
            $this->readForm($form)->version,
            $answers,
            SubmitToken::generate(),
            [],
            false,
            new GuestActor()
        );
    }

    public function testAnAdditionalFormThatClaimsANewPoolTakesItsSeat(): void
    {
        [$set, $source] = $this->tierSource();
        $this->registerInTierA($source, '');
        $form = $this->additional($this->field('text', 'tier') . $this->field('text', 'note'));
        $pool = new OptionSeatPool($this->event, $set, 'b');
        self::assertFalse((new Seats())->holds($pool, $this->registration));

        $this->sendWith($source, $form, ['tier' => 'b', 'note' => 'x']);

        self::assertSame('b', $this->values()['tier']);
        self::assertTrue((new Seats())->holds($pool, $this->registration));
    }

    public function testAFullPoolRefusesTheSubmitOnTheFieldWritesNothingAndKeepsTheOldSeat(): void
    {
        [$set, $source] = $this->tierSource(1);
        $this->registerInTierA($source, 'b', 'teszt.masik');
        $this->registerInTierA($source);
        $form = $this->additional($this->field('text', 'tier') . $this->field('text', 'note'));
        $submissions = $this->rowCount('kalaka_submissions');
        $seats = new Seats();

        try {
            $this->sendWith($source, $form, ['tier' => 'b', 'note' => 'x']);
            self::fail('A full pool did not refuse the additional form.');
        } catch (InvalidAnswers $refused) {
            self::assertSame(['tier' => SeatsFull::TEXT], $refused->fields);
        }
        self::assertSame('a', $this->values()['tier']);
        self::assertArrayNotHasKey('note', $this->values());
        self::assertSame($submissions, $this->rowCount('kalaka_submissions'));
        self::assertFalse($seats->holds(new OptionSeatPool($this->event, $set, 'b'), $this->registration));
        self::assertTrue($seats->holds(new OptionSeatPool($this->event, $set, 'a'), $this->registration));
    }

    public function testARechoiceBetweenTwoOptionsMovesTheSeat(): void
    {
        [$set, $source] = $this->tierSource();
        $this->registerInTierA($source);
        $form = $this->additional($this->field('text', 'tier') . $this->field('text', 'note'));

        $this->sendWith($source, $form, ['tier' => 'b', 'note' => 'x']);

        $seats = new Seats();
        self::assertSame('b', $this->values()['tier']);
        self::assertTrue($seats->holds(new OptionSeatPool($this->event, $set, 'b'), $this->registration));
        self::assertFalse($seats->holds(new OptionSeatPool($this->event, $set, 'a'), $this->registration));
    }

    public function testAnUnchangedClaimOrAFieldNeverTouchedDoesNothing(): void
    {
        [$set, $source] = $this->tierSource();
        $this->registerInTierA($source);
        $same = $this->additional($this->field('text', 'tier') . $this->field('text', 'note'));
        $other = $this->additional($this->field('text', 'note'));

        $this->sendWith($source, $same, ['tier' => 'a', 'note' => 'egy']);
        $this->sendWith($source, $other, ['note' => 'kettő']);

        $seats = new Seats();
        self::assertSame('a', $this->values()['tier']);
        self::assertSame('kettő', $this->values()['note']);
        self::assertTrue($seats->holds(new OptionSeatPool($this->event, $set, 'a'), $this->registration));
        self::assertSame(1, $seats->held(new OptionSeatPool($this->event, $set, 'a')));
    }

    public function testAWaitlistedRegistrationStoresTheAnswerWithNoSeatAndAdmissionTakesIt(): void
    {
        [$set, $source] = $this->tierSource();
        $this->registerInTierA($source, '');
        $this->setStatus('waitlisted');
        \Kalaka\Foundation\ObjectCache::clear();
        $form = $this->additional($this->field('text', 'tier') . $this->field('text', 'note'));
        $pool = new OptionSeatPool($this->event, $set, 'b');

        $this->sendWith($source, $form, ['tier' => 'b', 'note' => 'x']);

        self::assertSame('b', $this->values()['tier']);
        self::assertFalse((new Seats())->holds($pool, $this->registration));

        // Admission (a staff act): the registration becomes `registered`, then takes the seats its stored values
        // claim, as the admission use case will (S23).
        $this->setStatus('registered');
        \Kalaka\Foundation\ObjectCache::clear();
        $key = $this->registration;
        $claims = SeatClaims::of(
            [$source],
            (new \Kalaka\Events\Api\Events())->find($this->event) ?? self::fail('No event.'),
            new ValidatedAnswers($this->values(), $this->values(), array_keys($this->values()))
        );
        $this->runner->run(new AdminProbe(static function (array $input, Context $context) use ($key, $claims): null {
            (new Registrations())->lock($key, $context);
            foreach ($claims as $claim) {
                (new Seats())->retake($claim->pool, $key, SeatAct::Admission, $context);
            }

            return null;
        }), [], new CliActor(null));

        self::assertTrue((new Seats())->holds($pool, $key));
    }

    public function testARequestModeRechoiceClaimsNothingAndStillWorks(): void
    {
        $source = new SetOptionClaimSource();
        $this->registerInTierA($source, '');
        $form = $this->additional($this->field('text', 'tier'));

        $this->sendWith($source, $form, ['tier' => 'a']);
        $this->sendWith($source, $form, ['tier' => 'b']);

        self::assertSame('b', $this->values()['tier']);
        self::assertSame([], (new Seats())->poolsOf($this->registration));
    }
}
