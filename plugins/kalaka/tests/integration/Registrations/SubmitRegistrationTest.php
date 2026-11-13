<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\FormOutdated;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Api\SubmitTokenReused;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\SubmitRegistration;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

/** S13: the submit of a registration form, its capacity check, the "full" latch, the waitlist and repeats. */
final class SubmitRegistrationTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

    private function keyOf(SubmitResult $result): RegistrationKey
    {
        return $result->registration;
    }

    private const REGISTERED_TEXT = '<p>' . RegistrationSubmit::DEFAULT_REGISTERED . '</p>';

    private function submitter(): RegistrationSubmit
    {
        return new RegistrationSubmit($this->runner);
    }

    /** A published event with the capacity, and a registration-slot form of it. */
    private function openForm(int $capacity = 41, string $more = '', array $meta = [], array $post = []): int
    {
        $event = $this->create();
        $this->update($event, ['capacity' => $capacity]);
        $this->transition(new PublishEvent(), $event);

        $id = $this->insertForm($this->slotFields() . $more, $meta + ['kalaka_event_id' => $event], $post);

        return $id;
    }

    private function eventOf(int $form): int
    {
        return $this->readForm($form)->eventId;
    }

    /** @param array<string, mixed> $answers */
    private function submit(
        int $form,
        array $answers = self::ANSWERS,
        ?string $token = null,
        ?Actor $actor = null,
        bool $passwordGiven = false,
    ): SubmitResult {
        $version = $this->forms->form($form)?->version ?? 1;

        return $this->submitter()->submit(
            new SubmitRequest($form, $version, $answers, $token ?? SubmitToken::generate(), [], $passwordGiven),
            $actor ?? new GuestActor()
        );
    }

    private function seed(int $event, string $code, string $status): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
            'event_id' => $event,
            'code' => $code,
            'version' => 1,
            'status' => $status,
            'secret' => 'secret',
            'created_at' => '2026-11-29 08:00:00.000000',
            'registered_at' => $status === 'registered' ? '2026-11-29 08:00:00.000000' : null,
        ]);
    }

    /** @return list<array<string, ?string>> */
    private function tableRows(string $table, int $event): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection; table prefix and constants only
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$table} WHERE event_id = %d ORDER BY code", $event),
            ARRAY_A
        );
        self::assertIsArray($rows);

        return $rows;
    }

    private function assertNothingWritten(int $event): void
    {
        self::assertSame([], $this->tableRows('kalaka_registrations', $event));
        self::assertSame([], $this->tableRows('kalaka_submissions', $event));
        self::assertSame([], $this->tableRows('kalaka_field_values', $event));
    }

    public function testAGuestSubmitLandsRegisteredWithACodeItsSubmissionAndItsValues(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        $token = SubmitToken::generate();

        $result = $this->submit($form, self::ANSWERS, $token);

        self::assertSame($event, $this->keyOf($result)->eventId);
        self::assertTrue(ReferenceCode::isValid($this->keyOf($result)->code));
        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertFalse($result->repeated);
        self::assertSame(
            [
                'event' => $event,
                'code' => $this->keyOf($result)->code,
                'status' => 'registered',
                'repeated' => false,
                'after_submit' => self::REGISTERED_TEXT,
            ],
            $result->toArray()
        );

        $registration = $this->tableRows('kalaka_registrations', $event)[0];
        self::assertSame('registered', $registration['status']);
        self::assertSame('2026-11-30 08:00:00.000000', $registration['created_at']);
        self::assertSame('2026-11-30 08:00:00.000000', $registration['registered_at']);
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{22}$/', (string) $registration['secret']));

        $submission = $this->tableRows('kalaka_submissions', $event)[0];
        self::assertSame('1', $submission['number']);
        self::assertSame((string) $form, $submission['form_id']);
        self::assertSame((string) $this->readForm($form)->version, $submission['form_version']);
        self::assertSame(self::ANSWERS, json_decode((string) $submission['answers'], true));
        self::assertSame($token, $submission['submit_token']);
        self::assertSame(SubmitRegistration::fingerprint($form, self::ANSWERS), $submission['answers_hash']);
        self::assertSame(64, strlen((string) $submission['answers_hash']));

        $values = [];
        foreach ($this->tableRows('kalaka_field_values', $event) as $row) {
            $values[$row['field_key']] = $row['value'];
        }
        $expected = self::ANSWERS;
        ksort($expected);
        ksort($values);
        self::assertSame($expected, $values);
        self::assertSame('0', $this->row($event)['is_full']);
    }

    public function testTheAuditEntryNamesTheGuestAndKeepsTheSecretsOut(): void
    {
        $form = $this->openForm();
        $token = SubmitToken::generate();

        $result = $this->submit($form, self::ANSWERS, $token);

        $entries = $this->entriesOf('kalaka-portal/submit-registration');
        self::assertCount(1, $entries);
        self::assertSame('portal', $entries[0]['side']);
        self::assertSame('guest', $entries[0]['actor_type']);
        self::assertSame('succeeded', $entries[0]['outcome']);
        $changes = (string) $entries[0]['changes'];
        self::assertStringNotContainsString($token, $changes);
        $secret = $this->tableRows('kalaka_registrations', $this->keyOf($result)->eventId)[0]['secret'];
        self::assertStringNotContainsString((string) $secret, $changes);
        self::assertContains('field:email', $this->changedFields($entries[0]));
    }

    public function testAnAccountSubmitsLikeAGuest(): void
    {
        $result = $this->submit($this->openForm(), self::ANSWERS, null, new AccountActor(5));

        self::assertSame(RegistrationStatus::Registered, $result->status);
        self::assertSame('account', $this->entriesOf('kalaka-portal/submit-registration')[0]['actor_type']);
    }

    public function testARepeatWithTheSameTokenAndAnswersWritesNothingAndRevealsNothing(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        $token = SubmitToken::generate();

        $first = $this->submit($form, self::ANSWERS, $token);
        // The same answers in another order are the same answers.
        $second = $this->submit($form, array_reverse(self::ANSWERS, true), $token);
        $other = $this->submit($form, self::ANSWERS);

        self::assertFalse($first->repeated);
        self::assertTrue($second->repeated);
        // N13: only the after-submit content, rendered for the original registration.
        self::assertSame($this->keyOf($first)->code, $this->keyOf($second)->code);
        self::assertSame(['repeated' => true, 'after_submit' => self::REGISTERED_TEXT], $second->toArray());
        self::assertNotSame($this->keyOf($first)->code, $this->keyOf($other)->code);
        self::assertCount(2, $this->tableRows('kalaka_registrations', $event));
        self::assertCount(2, $this->tableRows('kalaka_submissions', $event));
    }

    /** #156 N12: the section renders with the registration's code and status; N13: a repeat with the original's. */
    public function testTheAfterSubmitSectionRendersForTheRegistration(): void
    {
        $paragraph = static fn (string $html): string
            => '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
        $status = static fn (string $value): string => wp_json_encode(['condition' => [
            'match' => 'all',
            'items' => [['key' => 'registration.status', 'operator' => 'contains', 'value' => $value]],
        ]]);
        $section = '<!-- wp:kalaka/after-submit -->'
            . $paragraph('Kód: <span data-kalaka-value="registration.code">kód</span>')
            . '<!-- wp:kalaka/if ' . $status('waitlisted') . ' -->' . $paragraph('Várólista') . '<!-- /wp:kalaka/if -->'
            . '<!-- wp:kalaka/else -->' . $paragraph('Bent vagy') . '<!-- /wp:kalaka/else -->'
            . '<!-- /wp:kalaka/after-submit -->';
        $form = $this->openForm(1, $section);
        $token = SubmitToken::generate();

        $first = $this->submit($form, self::ANSWERS, $token);
        $repeat = $this->submit($form, self::ANSWERS, $token);
        $waitlisted = $this->submit($form);

        self::assertStringContainsString($this->keyOf($first)->code, $first->afterSubmit);
        self::assertStringContainsString('Bent vagy', $first->afterSubmit);
        self::assertSame($first->afterSubmit, $repeat->afterSubmit);
        self::assertSame(['repeated', 'after_submit'], array_keys($repeat->toArray()));
        self::assertStringContainsString($this->keyOf($waitlisted)->code, $waitlisted->afterSubmit);
        self::assertStringContainsString('Várólista', $waitlisted->afterSubmit);
    }

    public function testWithoutASectionEachStatusGetsItsDefaultText(): void
    {
        $form = $this->openForm(1);

        self::assertSame(self::REGISTERED_TEXT, $this->submit($form)->afterSubmit);
        self::assertSame(
            '<p>Köszönjük a jelentkezésedet! Jelenleg nincs szabad hely, ezért várólistára kerültél. '
            . 'Figyeld az e-maileidet: értesítünk, ha be tudunk hívni az eseményre.</p>',
            $this->submit($form)->afterSubmit
        );
    }

    /** N6: a cached page's token sent with someone else's answers is refused and reveals nothing. */
    public function testTheSameTokenWithOtherAnswersIsRefusedAndWritesNothing(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        $token = SubmitToken::generate();
        $this->submit($form, self::ANSWERS, $token);

        try {
            $this->submit($form, ['name' => 'Teszt Elemér', 'email' => 'teszt2@example.org'], $token);
            self::fail('The reused token should have been refused.');
        } catch (SubmitTokenReused $reused) {
            self::assertSame('kalaka_token_reused', $reused->errorCode());
            self::assertSame(409, $reused->status());
            self::assertSame(
                'Ez az űrlap már nem küldhető be. Töltsd újra az oldalt, ha új jelentkezést szeretnél küldeni.',
                $reused->getMessage()
            );
            $data = $reused->toWpError()->get_error_data();
            self::assertIsArray($data);
            self::assertSame(['status', 'correlation'], array_keys($data));
        }
        self::assertCount(1, $this->tableRows('kalaka_registrations', $event));
        self::assertCount(1, $this->entriesOf('kalaka-portal/submit-registration'));
    }

    public function testTheSameTokenInAnotherEventIsAnotherSubmit(): void
    {
        $token = SubmitToken::generate();

        $first = $this->submit($this->openForm(), self::ANSWERS, $token);
        $second = $this->submit($this->openForm(), self::ANSWERS, $token);

        self::assertFalse($second->repeated);
        self::assertNotSame($this->keyOf($first)->eventId, $this->keyOf($second)->eventId);
    }

    public function testTheRegistrationThatDoesNotFitIsWaitlistedAndSetsTheLatch(): void
    {
        $form = $this->openForm(2);
        $event = $this->eventOf($form);

        $results = [$this->submit($form), $this->submit($form), $this->submit($form)];

        self::assertSame(
            [RegistrationStatus::Registered, RegistrationStatus::Registered, RegistrationStatus::Waitlisted],
            array_map(static fn (SubmitResult $result): RegistrationStatus => $result->status, $results)
        );
        self::assertSame('1', $this->row($event)['is_full']);
        $waitlisted = array_values(array_filter(
            $this->tableRows('kalaka_registrations', $event),
            static fn (array $row): bool => $row['status'] === 'waitlisted'
        ));
        self::assertCount(1, $waitlisted);
        self::assertNull($waitlisted[0]['registered_at']);
        self::assertSame('2026-11-30 08:00:00.000000', $waitlisted[0]['created_at']);
    }

    public function testWithTheLatchSetANewRegistrationIsWaitlistedEvenWhenAPlaceIsFree(): void
    {
        $form = $this->openForm(1);
        $event = $this->eventOf($form);
        $first = $this->submit($form);
        $this->submit($form);
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'withdrawn'],
            ['event_id' => $event, 'code' => $this->keyOf($first)->code]
        );

        self::assertSame(RegistrationStatus::Waitlisted, $this->submit($form)->status);
    }

    public function testCapacityZeroWaitlistsTheFirstRegistration(): void
    {
        $form = $this->openForm(0);

        self::assertSame(RegistrationStatus::Waitlisted, $this->submit($form)->status);
        self::assertSame('1', $this->row($this->eventOf($form))['is_full']);
    }

    public function testOnlyRegisteredRegistrationsTakeAPlace(): void
    {
        $form = $this->openForm(1);
        $event = $this->eventOf($form);
        $this->seed($event, '00A0', 'withdrawn');
        $this->seed($event, '00A2', 'removed');
        $this->seed($event, '00A5', 'waitlisted');

        self::assertSame(RegistrationStatus::Registered, $this->submit($form)->status);
        self::assertSame('0', $this->row($event)['is_full']);
    }

    public function testAnUnpublishedEventRefusesTheSubmitAndWritesNothing(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        $this->transition(new \Kalaka\Events\UnpublishEvent(), $event);

        try {
            $this->submit($form);
            self::fail('The submit should have been refused.');
        } catch (FormRefused $refused) {
            self::assertSame([AccessRefusal::EventNotOpen], $refused->refusals);
            self::assertSame(403, $refused->status());
            self::assertSame(['event_not_open'], $refused->toWpError()->get_error_data()['refusals'] ?? null);
        }
        $this->assertNothingWritten($event);
        self::assertSame([], $this->entriesOf('kalaka-portal/submit-registration'));
    }

    public function testOutsideTheRegistrationWindowTheSubmitIsRefused(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        // The closing day is 2026-12-15 in Budapest; the next day starts at 23:00 UTC.
        $this->useClock(new TestClock(new \DateTimeImmutable('2026-12-15 23:00:00', new \DateTimeZone('UTC'))));

        try {
            $this->submit($form);
            self::fail('The submit should have been refused.');
        } catch (FormRefused $refused) {
            self::assertSame([AccessRefusal::OutsideRegistrationWindow], $refused->refusals);
        }
        $this->assertNothingWritten($event);
    }

    public function testAPasswordFormNeedsThePassword(): void
    {
        $form = $this->openForm(41, '', [], ['post_password' => 'teszt']);

        try {
            $this->submit($form);
            self::fail('The submit should have been refused.');
        } catch (FormRefused $refused) {
            self::assertSame([AccessRefusal::PasswordRequired], $refused->refusals);
        }
        self::assertSame(RegistrationStatus::Registered, $this->submit($form, passwordGiven: true)->status);
    }

    public function testWrongAnswersAreRefusedBeforeTheRunAndWriteNothing(): void
    {
        $form = $this->openForm();

        try {
            $this->submit($form, ['name' => 'Teszt Elek']);
            self::fail('The answers should have been refused.');
        } catch (InvalidAnswers $invalid) {
            self::assertArrayHasKey('email', $invalid->fields);
        }
        $this->assertNothingWritten($this->eventOf($form));
        self::assertSame([], $this->entriesOf('kalaka-portal/submit-registration'));
    }

    public function testAPageBuiltFromAnEarlierVersionIsRefusedAndWritesNothing(): void
    {
        $form = $this->openForm();
        $old = $this->readForm($form)->version;
        wp_update_post(['ID' => $form, 'post_content' => $this->slotFields() . $this->field('text', 'note')]);
        self::assertNotSame($old, $this->readForm($form)->version);

        try {
            $this->submitter()->submit(
                new SubmitRequest($form, $old, self::ANSWERS, SubmitToken::generate()),
                new GuestActor()
            );
            self::fail('The submit should have been refused.');
        } catch (FormOutdated $outdated) {
            self::assertSame('kalaka_form_outdated', $outdated->errorCode());
            self::assertSame(
                'Az űrlap időközben megváltozott. Töltsd újra az oldalt, és próbáld újra.',
                $outdated->getMessage()
            );
        }
        $this->assertNothingWritten($this->eventOf($form));
    }

    /** Keys derived from the titles on save (#52) are the keys the answers and the values use. */
    public function testDerivedKeysAreTheKeysOfTheValues(): void
    {
        $event = $this->create();
        $this->update($event);
        $this->transition(new PublishEvent(), $event);
        $content = $this->block('kalaka/name-field', ['label' => 'Teljes név', 'displayName' => true])
            . $this->block('kalaka/email-field', ['label' => 'E-mail-cím', 'contactEmail' => true]);
        $form = $this->insertForm($content, ['kalaka_event_id' => $event]);
        $keys = array_map(
            static fn ($field): string => $field->key,
            $this->forms->definition($this->readForm($form))->fields
        );
        self::assertSame(['teljes_nev', 'e_mail_cim'], $keys);

        $this->submit($form, ['teljes_nev' => 'Teszt Elek', 'e_mail_cim' => 'teszt@example.org']);

        self::assertSame(
            ['e_mail_cim', 'teljes_nev'],
            array_map(static fn (array $row): string => (string) $row['field_key'], $this->fieldRows($event))
        );
    }

    /** @param array<string, mixed> $set */
    private function changeParticipantField(int $event, string $key, array $set): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_participant_fields', $set, ['event_id' => $event, 'field_key' => $key]);
    }

    /** @return iterable<string, array{array<string, mixed>|null, string}> the change of `email`'s row, the problem */
    public static function listDisagreements(): iterable
    {
        yield 'a key the event does not declare' => [null, 'undeclared_field_key email'];
        yield 'a retired key' => [['retired_at' => '2026-11-29 08:00:00.000000'], 'undeclared_field_key email'];
        // Another type is caught before the run: the form's current version is closed (#169's type check).
        yield 'another type' => [['type' => 'kalaka/text-field'], 'type_clash email'];
        yield 'a system field' => [['source' => 'system'], 'system_field email'];
        yield 'a field no form may write' => [['source' => 'extension', 'form_writable' => 0], 'extension_field email'];
    }

    /**
     * N17: field values are written only through the event's participant-field list: a key the list does
     * not hold as an active field of that type, or a `system` field, fails the submit and writes nothing.
     *
     * @dataProvider listDisagreements
     * @param array<string, mixed>|null $set
     */
    public function testAStoredAnswerOutsideTheParticipantFieldListWritesNothing(?array $set, string $problem): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);
        if ($set === null) {
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'kalaka_participant_fields', ['event_id' => $event, 'field_key' => 'email']);
        } else {
            $this->changeParticipantField($event, 'email', $set);
        }

        try {
            $this->submit($form);
            self::fail('The submit should have failed.');
        } catch (SystemFailure $failure) {
            self::assertStringContainsString($problem, $failure->getMessage());
        }
        $this->assertNothingWritten($event);
    }

    public function testTheParticipantFieldListHoldsTheFormsKeysAndTheSubmitWritesThem(): void
    {
        $form = $this->openForm();
        $event = $this->eventOf($form);

        $keys = array_map(
            static fn ($field): string => $field->key,
            (new \Kalaka\Forms\Api\Forms())->participantFields($event)
        );
        sort($keys);
        self::assertSame(['email', 'name'], $keys);
        $this->submit($form);
        self::assertCount(2, $this->tableRows('kalaka_field_values', $event));
    }

    /** N15: a form that submissions refer to is never deleted permanently; the trash stays allowed. */
    public function testAFormWithSubmissionsIsNeverDeletedPermanently(): void
    {
        $form = $this->openForm();
        $empty = $this->openForm();
        $this->submit($form);

        self::assertFalse(wp_delete_post($form, true));
        self::assertInstanceOf(\WP_Post::class, get_post($form));
        self::assertInstanceOf(\WP_Post::class, wp_trash_post($form));
        self::assertFalse(wp_delete_post($form, true));
        self::assertInstanceOf(\WP_Post::class, get_post($form));

        self::assertInstanceOf(\WP_Post::class, wp_delete_post($empty, true));
        self::assertNull(get_post($empty));
    }

    public function testARestForcedDeleteOfAFormWithSubmissionsIsRefused(): void
    {
        $form = $this->openForm();
        $this->submit($form);
        wp_set_current_user($this->fullAccessUser());
        $base = get_post_type_object('kalaka_form')?->rest_base;
        $base = is_string($base) && $base !== '' ? $base : 'kalaka_form';

        $request = new \WP_REST_Request('DELETE', '/wp/v2/' . $base . '/' . $form);
        $request->set_param('force', true);
        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('kalaka_form_has_submissions', $response->as_error()?->get_error_code());
        self::assertSame('A művelet nem hajtható végre.', $response->as_error()?->get_error_message());
        self::assertInstanceOf(\WP_Post::class, get_post($form));
        wp_set_current_user(0);
    }

    /** N16: the submissions half of #169 N4: a form that submissions refer to keeps its event. */
    public function testAFormWithSubmissionsKeepsItsEventEvenWithoutFields(): void
    {
        global $wpdb;
        $event = $this->create();
        $this->update($event);
        $other = $this->create('Másik tábor');
        $paragraph = '<!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph -->';
        $form = $this->insertForm($paragraph, ['kalaka_event_id' => $event, 'kalaka_placement' => 'additional']);
        $free = $this->insertForm($paragraph, ['kalaka_event_id' => $event, 'kalaka_placement' => 'additional']);
        $wpdb->insert($wpdb->prefix . 'kalaka_submissions', [
            'event_id' => $event,
            'code' => '00A0',
            'number' => 2,
            'form_id' => $form,
            'form_version' => 1,
            'answers' => '{}',
            'submitted_at' => '2026-11-30 08:00:00.000000',
        ]);

        self::assertFalse(update_post_meta($form, 'kalaka_event_id', $other));
        self::assertSame((string) $event, get_post_meta($form, 'kalaka_event_id', true));
        self::assertNotFalse(update_post_meta($free, 'kalaka_event_id', $other));
        self::assertSame((string) $other, get_post_meta($free, 'kalaka_event_id', true));
    }

    public function testAMalformedTokenIsInvalidInput(): void
    {
        $form = $this->openForm();

        $this->expectException(InvalidInput::class);
        $this->submit($form, self::ANSWERS, 'not-a-token');
    }

    public function testAnUnknownFormIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->submit(999999);
    }

    public function testAnAdditionalFormCreatesNoRegistration(): void
    {
        $event = $this->create();
        $this->update($event);
        $this->transition(new PublishEvent(), $event);
        $form = $this->insertForm(
            $this->field('text', 'note'),
            ['kalaka_event_id' => $event, 'kalaka_placement' => 'additional']
        );

        $this->expectException(RuleViolation::class);
        $this->submit($form, ['note' => 'Teszt']);
    }

    public function testAnAnswerInABranchThatDoesNotHoldIsDroppedFromTheSubmissionAndTheValues(): void
    {
        $choice = $this->field('choice', 'track', ['options' => [
            ['value' => 'alap', 'label' => 'Alap'],
            ['value' => 'kulon', 'label' => 'Külön'],
        ]]);
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => [
            'match' => 'all',
            'items' => [['key' => 'track', 'operator' => 'equals', 'value' => 'kulon']],
        ]]) . ' -->' . $this->field('text', 'extra') . '<!-- /wp:kalaka/if -->';
        $form = $this->openForm(41, $choice . $branch);
        $event = $this->eventOf($form);

        $this->submit($form, self::ANSWERS + ['track' => 'alap', 'extra' => 'Teszt']);

        $answers = json_decode((string) $this->tableRows('kalaka_submissions', $event)[0]['answers'], true);
        self::assertIsArray($answers);
        self::assertArrayNotHasKey('extra', $answers);
        self::assertSame(
            ['email', 'name', 'track'],
            array_map(static fn (array $row): string => (string) $row['field_key'], $this->fieldRows($event))
        );
    }

    public function testConditionsReadTheEventsValues(): void
    {
        // Required while the event has a name: without the event's values the walk could not read it.
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => [
            'match' => 'all',
            'items' => [['key' => 'event.name', 'operator' => 'empty', 'not' => true]],
        ]]) . ' -->' . $this->field('text', 'extra', ['required' => true]) . '<!-- /wp:kalaka/if -->';
        $form = $this->openForm(41, $branch);

        try {
            $this->submit($form);
            self::fail('The answers should have been refused.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['extra'], array_keys($invalid->fields));
        }
        $result = $this->submit($form, self::ANSWERS + ['extra' => 'Teszt']);
        self::assertSame(RegistrationStatus::Registered, $result->status);
    }

    public function testAnEventChangedSinceTheAnswersWereCheckedIsAVersionConflict(): void
    {
        $form = $this->readForm($this->openForm());
        $version = $this->versionOf($form->eventId);
        $useCase = new SubmitRegistration(
            $form,
            $form->version,
            new ValidatedAnswers(self::ANSWERS, self::ANSWERS),
            $version - 1,
            [],
            false,
        );

        try {
            $this->runner->run($useCase, ['form' => $form->id, 'token' => SubmitToken::generate()], new GuestActor());
            self::fail('The run should have failed.');
        } catch (VersionConflict) {
            $this->assertNothingWritten($form->eventId);
        }
    }

    public function testTheUseCaseRefusesAnotherFormThanItWasBuiltFor(): void
    {
        $form = $this->readForm($this->openForm());
        $useCase = new SubmitRegistration(
            $form,
            $form->version,
            new ValidatedAnswers(self::ANSWERS, self::ANSWERS),
            $this->versionOf($form->eventId),
            [],
            false,
        );

        $this->expectException(\Kalaka\Foundation\Api\Error\SystemFailure::class);
        $this->runner->run($useCase, ['form' => $form->id + 1, 'token' => SubmitToken::generate()], new GuestActor());
    }

    /** @return list<array<string, ?string>> */
    private function fieldRows(int $event): array
    {
        $rows = $this->tableRows('kalaka_field_values', $event);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['field_key'], (string) $b['field_key']));

        return $rows;
    }

    public function testTheKeyOfTheResultIsCanonical(): void
    {
        $result = $this->submit($this->openForm());

        self::assertEquals(
            new RegistrationKey($this->keyOf($result)->eventId, $this->keyOf($result)->code),
            $this->keyOf($result)
        );
    }
}
