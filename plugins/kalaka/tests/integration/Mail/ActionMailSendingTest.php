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

    public function testABodyThatCannotRenderIsAnErrorThatNamesNoValue(): void
    {
        $event = $this->published();
        $key = $this->registration($event);
        $this->switchOn($event, 'registered', $this->document(
            $event,
            $this->paragraph(
                '<span data-kalaka-value="registration.link">l</span> <span data-kalaka-value="nincs">x</span>'
            )
        ));
        $this->act($key, ActionMail::Registered);

        $this->realSender()->send();

        self::assertSame([], $this->sent);
        $row = $this->deliveries()[0];
        self::assertSame('pending', $row['status']);
        self::assertStringContainsString('unknown_value_key nincs', (string) $row['error']);
        $this->assertSecretNowhereStored();
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
