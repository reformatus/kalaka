<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Mail\Body\ActionMailComposer;
use Kalaka\Mail\Sender;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * Deciding an action mail and sending it with the action mails' composer: the trigger interface, tested
 * with a use case standing in for the actions that do not exist yet. mail.md, "Five kinds of mail".
 */
final class ActionMailSendingTest extends MailBodyTestCase
{
    /** @var list<array<string, mixed>> what `wp_mail` was called with */
    private array $sent = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->sent = [];
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function (mixed $result, array $atts): bool {
            $this->sent[] = $atts;

            return true;
        }, 10, 2);
    }

    /** The stand-in for an action's use case: it decides the action mail, as the action will. */
    private function act(RegistrationKey $key, ActionMail $action, string $causeKey = 'c1'): bool
    {
        $queued = $this->runner->run(
            new AdminProbe(static fn (array $input, Context $context): bool => (new ActionMails())->due(
                $context,
                $action,
                $key,
                new Subject('registration', $key->eventId, $causeKey)
            )),
            [],
            new CliActor(null)
        );
        self::assertIsBool($queued);

        return $queued;
    }

    private function realSender(): Sender
    {
        return new Sender(fn () => $this->runner, new ActionMailComposer());
    }

    public function testAMailThatIsOffQueuesNothing(): void
    {
        $event = $this->published();
        $key = $this->registration($event);

        self::assertFalse($this->act($key, ActionMail::Registered));
        self::assertSame([], $this->deliveries());
    }

    public function testAMailThatIsOnQueuesOneActionDeliveryKeyedByItsCause(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));

        self::assertTrue($this->act($key, ActionMail::Registered));
        self::assertFalse($this->act($key, ActionMail::Registered));
        self::assertTrue($this->act($key, ActionMail::Registered, 'c2'));

        $rows = $this->deliveries();
        self::assertCount(2, $rows);
        self::assertSame(['registered', 'action', '12A3', 'registration', 'c1'], [
            $rows[0]['mail'], $rows[0]['kind'], $rows[0]['code'], $rows[0]['cause_type'], $rows[0]['cause_key'],
        ]);
    }

    public function testAWaitlistedRegistrationQueuesOnlyTheWaitlistMailAndAdmissionLiftsTheHold(): void
    {
        global $wpdb;
        $event = $this->published();
        $values = ['name' => 'Teszt Elek', 'email' => 'teszt@example.test', 'camps' => '["ff"]'];
        $key = $this->registration($event, '12A3', $values, 'waitlisted');
        foreach (['waitlisted', 'registered', 'answered', 'payment_recorded'] as $mail) {
            $this->switchOn($event, $mail, $this->document($event, $this->paragraph('Szia')));
        }

        self::assertTrue($this->act($key, ActionMail::Waitlisted));
        self::assertFalse($this->act($key, ActionMail::Answered));
        self::assertFalse($this->act($key, ActionMail::PaymentRecorded));
        self::assertFalse($this->act($key, ActionMail::Registered, 'c2'));
        self::assertSame(['waitlisted'], array_column($this->deliveries(), 'mail'));

        // Admission: the status is `registered` when the admission mail is decided, so nothing holds it; a held
        // mail is not queued later by itself.
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'registered'],
            ['event_id' => $event, 'code' => '12A3'],
        );
        self::assertTrue($this->act($key, ActionMail::Registered, 'c3'));
        self::assertTrue($this->act($key, ActionMail::Answered, 'c4'));
        self::assertSame(['waitlisted', 'registered', 'answered'], array_column($this->deliveries(), 'mail'));
    }

    public function testAnUnpublishedEventStillQueuesItsActionMails(): void
    {
        $event = $this->unpublished();
        $key = $this->registration($event);
        $this->switchOn($event, 'waitlisted', $this->document($event, $this->paragraph('Szia')));

        self::assertTrue($this->act($key, ActionMail::Waitlisted));
    }

    public function testTheSenderSendsTheRenderedMailToTheContactEmailAndStoresOnlyItsVersion(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $body = $this->document(
            $event,
            $this->paragraph('Linked: <span data-kalaka-value="registration.link">link</span>'),
            'Új link'
        );
        $this->switchOn($event, 'link_regenerated', $body);
        $this->act($key, ActionMail::LinkRegenerated);

        $this->realSender()->send();

        self::assertCount(1, $this->sent);
        self::assertSame('teszt@example.test', $this->sent[0]['to']);
        self::assertSame('Új link', $this->sent[0]['subject']);
        self::assertStringContainsString(self::SECRET, (string) $this->sent[0]['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $this->sent[0]['headers']);
        $row = $this->deliveries()[0];
        self::assertSame('sent', $row['status']);
        $revision = wp_get_latest_revision_id_and_total_count($body);
        self::assertIsArray($revision);
        self::assertSame('b' . $revision['latest_id'], $row['mail_version']);
        $this->assertSecretNowhereStored();
    }

    public function testARegistrationWithoutAContactEmailFailsWithoutAnAttempt(): void
    {
        $event = $this->published();
        $key = $this->registration($event, values: ['name' => 'Teszt']);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));
        $this->act($key, ActionMail::Registered);

        $this->realSender()->send();

        self::assertSame([], $this->sent);
        self::assertSame('failed', $this->deliveries()[0]['status']);
        self::assertSame('0', $this->deliveries()[0]['attempts']);
    }

    public function testAnUnpublishedSlotFormFailsTheMailWithItsOwnReasonNotABadAddress(): void
    {
        $event = $this->published();
        $form = $this->slotForm($event);
        $key = $this->registration($event, form: $form);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));
        $this->act($key, ActionMail::Registered);
        wp_update_post(['ID' => $form, 'post_status' => 'draft']);

        $report = $this->realSender()->send();

        self::assertSame([], $this->sent);
        $row = $this->deliveries()[0];
        self::assertSame('failed', $row['status']);
        self::assertSame('0', $row['attempts']);
        self::assertStringStartsWith('address no_recipient_form (correlation ', (string) $row['error']);
        self::assertNotNull($report);
        self::assertSame(1, $report->invalidAddresses);
        self::assertFalse($report->paused);
    }

    public function testARegistrationWithoutItsFirstSubmissionFailsTheMailAsNoRegistration(): void
    {
        global $wpdb;
        $event = $this->published();
        $key = $this->registration($event);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));
        $this->act($key, ActionMail::Registered);
        $wpdb->delete($wpdb->prefix . 'kalaka_submissions', ['event_id' => $event, 'code' => $key->code]);

        $report = $this->realSender()->send();

        self::assertSame([], $this->sent);
        $row = $this->deliveries()[0];
        self::assertSame('failed', $row['status']);
        self::assertSame('0', $row['attempts']);
        self::assertStringStartsWith('address no_registration (correlation ', (string) $row['error']);
        self::assertNotNull($report);
        self::assertSame(1, $report->invalidAddresses);
        self::assertFalse($report->paused);
    }

    public function testABodyThatCannotRenderFailsWithItsProblemWithoutAnAttemptAndDoesNotPause(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $this->switchOn($event, 'registered', $this->brokenDocument(
            $event,
            $this->paragraph(
                '<span data-kalaka-value="registration.link">l</span> <span data-kalaka-value="nincs">x</span>'
            )
        ));
        $this->act($key, ActionMail::Registered);
        $this->act($this->registration($event, '00A0'), ActionMail::Registered);

        $report = $this->realSender()->send();

        self::assertSame([], $this->sent);
        foreach ($this->deliveries() as $row) {
            self::assertSame('failed', $row['status']);
            self::assertSame('0', $row['attempts']);
            self::assertStringStartsWith('body unknown_value_key nincs (correlation ', (string) $row['error']);
        }
        self::assertNotNull($report);
        self::assertSame(2, $report->unusableBodies);
        self::assertSame(0, $report->errors);
        self::assertFalse($report->paused);
        self::assertNull($this->stateRow()['paused_at'] ?? null);
        $this->assertSecretNowhereStored();
    }

    /** @return iterable<string, array{\Closure(int, int, int): void, string}> what happens to the body, the problem */
    public static function bodyChanges(): iterable
    {
        yield 'removed' => [static function (int $body): void {
            wp_delete_post($body, true);
        }, 'missing'];
        yield 'unpublished' => [static function (int $body): void {
            wp_update_post(['ID' => $body, 'post_status' => 'draft']);
        }, 'unpublished'];
        yield 'moved' => [static function (int $body, int $event, int $other): void {
            update_post_meta($body, 'kalaka_event_id', $other);
        }, 'other_event'];
        yield 'unassigned' => [static function (): void {
        }, 'no_body'];
    }

    /**
     * A delivery queued while its mail was on, whose body then changes after a switch-off (when the body
     * may change): the delivery fails with the body's problem, no attempt, and sending does not pause.
     *
     * @dataProvider bodyChanges
     */
    public function testABodyChangedAfterASwitchOffFailsItsQueuedDeliveriesWithoutPausing(
        \Closure $change,
        string $problem
    ): void {
        $event = $this->published();
        $other = $this->create('Másik');
        $body = $this->document($event, $this->paragraph('Szia'));
        $this->switchOn($event, 'registered', $body);
        $this->act($this->registration($event), ActionMail::Registered);
        $this->act($this->registration($event, '00A0'), ActionMail::Registered);
        $this->updateMail([
            'event_id' => $event,
            'mail' => 'registered',
            'enabled' => false,
        ] + ($problem === 'no_body' ? ['body_id' => null] : []));
        $change($body, $event, $other);

        $report = $this->realSender()->send();

        self::assertSame([], $this->sent);
        $expected = $problem === 'no_body' ? 'no_body registered' : $problem . ' ' . $body;
        foreach ($this->deliveries() as $row) {
            self::assertSame('failed', $row['status']);
            self::assertSame('0', $row['attempts']);
            self::assertStringStartsWith('body ' . $expected . ' (correlation ', (string) $row['error']);
        }
        self::assertNotNull($report);
        self::assertSame(2, $report->unusableBodies);
        self::assertSame(0, $report->errors);
        self::assertFalse($report->paused);
    }

    public function testAnyOtherRenderFailureIsAnErrorAfterTheClaimThatCounts(): void
    {
        global $wpdb;
        $event = $this->published();
        $key = $this->registration($event);
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Szia')));
        $this->act($key, ActionMail::Registered);
        // A stored layout written around its use case: the render throws, not the body's own problem.
        $wpdb->insert($wpdb->prefix . 'kalaka_mail_layouts', ['event_id' => $event, 'version' => 1, 'html' => 'x']);

        $report = $this->realSender()->send();

        self::assertSame([], $this->sent);
        $row = $this->deliveries()[0];
        self::assertSame('pending', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertStringStartsWith('compose ', (string) $row['error']);
        self::assertNotNull($report);
        self::assertSame(1, $report->errors);
    }

    /** The secret is in no delivery row and in no audit entry. */
    private function assertSecretNowhereStored(): void
    {
        global $wpdb;
        $tables = ['kalaka_deliveries', 'kalaka_audit_entries', 'kalaka_audit_subjects', 'kalaka_mail_states'];
        foreach ($tables as $table) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $wpdb->prefix . $table), ARRAY_A);
            self::assertStringNotContainsString(self::SECRET, (string) wp_json_encode($rows), $table);
        }
    }
}
