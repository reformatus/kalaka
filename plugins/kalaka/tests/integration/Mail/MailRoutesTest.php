<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Pause\PauseMail;

/**
 * The mail screens' reads under `kalaka-admin/v1`: the gate, the 404 after it, the shapes, `can` and `refusals`
 * from the use case's own check, and the checked preview.
 */
final class MailRoutesTest extends MailAdminTestCase
{
    private const SHAPES = [
        '/events/%d/mail',
        '/mail',
        '/events/%d/mail/troubled',
        '/events/%d/mail/batches',
        '/events/%d/mail/batches/1',
    ];

    private function path(string $route, int $event): string
    {
        return str_contains($route, '%d') ? sprintf($route, $event) : $route;
    }

    public function testOnlyFullAccessReadsTheFiveReads(): void
    {
        [$event] = $this->ready();
        $holder = $this->holder($event);
        $plain = self::factory()->user->create(['role' => 'subscriber']);

        foreach ([0, $plain, $holder] as $user) {
            $this->as($user);
            foreach (self::SHAPES as $route) {
                self::assertContains(
                    $this->rest('GET', $this->path($route, $event))->get_status(),
                    [401, 403],
                    $route . ' for user ' . $user
                );
            }
        }
        $this->as($this->admin->userId);
        foreach (['/events/%d/mail', '/mail', '/events/%d/mail/troubled', '/events/%d/mail/batches'] as $route) {
            self::assertSame(200, $this->rest('GET', $this->path($route, $event))->get_status(), $route);
        }
    }

    public function testTheEventIsLookedForAfterTheGate(): void
    {
        $this->as($this->admin->userId);
        foreach (['/events/%d/mail', '/events/%d/mail/troubled', '/events/%d/mail/batches'] as $route) {
            self::assertSame(404, $this->rest('GET', $this->path($route, 99999))->get_status(), $route);
        }
        [$event] = $this->ready();
        self::assertSame(404, $this->rest('GET', '/events/' . $event . '/mail/batches/99999')->get_status());

        $this->as(self::factory()->user->create(['role' => 'subscriber']));
        self::assertSame(403, $this->rest('GET', '/events/99999/mail')->get_status());
    }

    public function testTheEventMailPageHasTheCatalogueTheBodiesTheCountsAndTheGates(): void
    {
        [$event, $body] = $this->ready();
        $draft = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'draft',
            'post_title' => 'Vázlat',
            'meta_input' => ['kalaka_event_id' => $event],
        ]);
        $trashed = $this->document($event, $this->paragraph('x'), 'Törölt');
        wp_trash_post($trashed);
        $other = $this->published();
        $this->document($other, $this->paragraph('x'), 'Másik eseményé');
        $this->switchOn($event, 'registered', $body);
        $this->queue($event, '00A0');
        $this->as($this->admin->userId);

        $response = $this->rest('GET', '/events/' . $event . '/mail');
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['event_id', 'held', 'layout_html', 'mails', 'bodies', 'stats', 'can'],
            array_keys($data)
        );
        self::assertSame([$event, false, '{{content}}'], [$data['event_id'], $data['held'], $data['layout_html']]);
        self::assertSame(
            ['registered', 'waitlisted', 'payment_recorded', 'confirmed', 'link_regenerated'],
            array_column($data['mails'], 'mail')
        );
        $registered = $data['mails'][0];
        self::assertSame(
            ['mail', 'label', 'enabled', 'body_id', 'layout', 'can', 'refusals'],
            array_keys($registered)
        );
        self::assertSame('Jelentkezés rögzítve', $registered['label']);
        self::assertSame([true, $body, true], [$registered['enabled'], $registered['body_id'], $registered['layout']]);
        self::assertSame(['switch_on' => false, 'switch_off' => true], $registered['can']);
        self::assertSame(['switch_on' => null, 'switch_off' => null], $registered['refusals']);
        // Without a body a mail cannot be switched on, and the use case's own code and text say why.
        $waitlisted = $data['mails'][1];
        self::assertSame(['switch_on' => false, 'switch_off' => false], $waitlisted['can']);
        self::assertSame(
            [
                'code' => 'kalaka_mail_no_body',
                'message' => 'A művelet nem hajtható végre. Előbb add meg a sablon szövegét.',
            ],
            $waitlisted['refusals']['switch_on']
        );

        // The event's own bodies by title, the trashed one and another event's left out.
        self::assertSame(['Sikeres jelentkezés', 'Vázlat'], array_column($data['bodies'], 'subject'));
        self::assertSame(
            ['id', 'subject', 'status', 'used_by', 'modified', 'edit_url'],
            array_keys($data['bodies'][0])
        );
        self::assertSame([$body, 'publish', 'registered'], [
            $data['bodies'][0]['id'], $data['bodies'][0]['status'], $data['bodies'][0]['used_by'],
        ]);
        self::assertSame([$draft, 'draft', null], [
            $data['bodies'][1]['id'], $data['bodies'][1]['status'], $data['bodies'][1]['used_by'],
        ]);
        self::assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $data['bodies'][0]['modified']);
        self::assertStringEndsWith('post.php?post=' . $body . '&action=edit', $data['bodies'][0]['edit_url']);
        self::assertSame(
            ['queued' => 1, 'sent' => 0, 'failed' => 0, 'with_error' => 0, 'stuck_sending' => 0],
            $data['stats']
        );
        // The event is published, so its mail is on its way and cannot be cleared.
        self::assertSame(['clear_event' => false], $data['can']);
    }

    public function testAnArchivedEventIsListedOnlyWhileSomethingOfItIsQueuedOrFailed(): void
    {
        global $wpdb;
        $kept = $this->published();
        $this->queue($kept, '00A0');
        $this->transition(new UnpublishEvent(), $kept);
        $this->transition(new ArchiveEvent(), $kept);
        $this->as($this->admin->userId);

        $listed = $this->rest('GET', '/mail')->get_data()['events'];
        self::assertSame([$kept], array_column($listed, 'event_id'));
        self::assertSame(['clear_event' => true], $listed[0]['can']);

        // Failed counts as much as queued; sent alone does not.
        $table = $wpdb->prefix . 'kalaka_deliveries';
        $wpdb->update($table, ['status' => 'failed'], ['event_id' => $kept]);
        self::assertSame([$kept], array_column($this->rest('GET', '/mail')->get_data()['events'], 'event_id'));
        $wpdb->update($table, ['status' => 'sent'], ['event_id' => $kept]);
        self::assertSame([], $this->rest('GET', '/mail')->get_data()['events']);
    }

    public function testAZeroDateReadsAsNullInTheMailReads(): void
    {
        global $wpdb;
        [$event] = $this->ready();
        $draft = $this->document($event, $this->paragraph('x'), 'Vázlat');
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);
        $wpdb->update($wpdb->posts, ['post_modified_gmt' => '0000-00-00 00:00:00'], ['ID' => $draft]);
        clean_post_cache($draft);
        $this->as($this->admin->userId);

        $bodies = $this->rest('GET', '/events/' . $event . '/mail')->get_data()['bodies'];

        $row = array_values(array_filter($bodies, static fn (array $body): bool => $body['id'] === $draft))[0];
        self::assertNull($row['modified']);
    }

    private function nameOf(int $event): string
    {
        $found = (new \Kalaka\Events\Api\Events())->find($event);
        self::assertNotNull($found);

        return $found->name;
    }

    public function testAHeldEventMayBeCleared(): void
    {
        [$event] = $this->ready();
        $this->transition(new UnpublishEvent(), $event);
        $this->as($this->admin->userId);

        $data = $this->rest('GET', '/events/' . $event . '/mail')->get_data();

        self::assertTrue($data['held']);
        self::assertSame(['clear_event' => true], $data['can']);
    }

    public function testASwitchedOffMailWhoseBodyIsGoneStillReadsWithItsRefusal(): void
    {
        [$event, $body] = $this->ready();
        $this->updateMail(['event_id' => $event, 'mail' => 'waitlisted', 'body_id' => $body]);
        wp_trash_post($body);
        $this->as($this->admin->userId);

        $response = $this->rest('GET', '/events/' . $event . '/mail');

        self::assertSame(200, $response->get_status());
        $waitlisted = $response->get_data()['mails'][1];
        self::assertSame($body, $waitlisted['body_id']);
        self::assertFalse($waitlisted['can']['switch_on']);
        self::assertSame('kalaka_mail_body_unpublished', $waitlisted['refusals']['switch_on']['code']);
    }

    public function testTheAccountRulesComeAsTheirTypedRefusals(): void
    {
        global $wpdb;
        $event = $this->published();
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();
        $withLink = $this->document(
            $event,
            $this->paragraph('Jelszó: <span data-kalaka-value="account.password_link">link</span>')
        );
        $this->switchOn($event, 'registered', $withLink);
        $this->as($this->admin->userId);

        $registered = $this->rest('GET', '/events/' . $event . '/mail')->get_data()['mails'][0];

        self::assertFalse($registered['can']['switch_off']);
        self::assertSame('kalaka_mail_registered_stays_on', $registered['refusals']['switch_off']['code']);
        self::assertSame(['switch_on' => false, 'switch_off' => false], $registered['can']);
    }

    public function testTheSenderStateCarriesThePauseTheErrorTheBudgetAndEachEventsCounts(): void
    {
        [$event] = $this->ready();
        $this->as($this->admin->userId);
        $idle = $this->rest('GET', '/mail')->get_data();
        self::assertSame(
            ['paused', 'errors_in_row', 'hourly_budget', 'hourly_budget_default', 'queued', 'events', 'can'],
            array_keys($idle)
        );
        self::assertSame([null, 0, 240, 0, []], [
            $idle['paused'], $idle['errors_in_row'], $idle['hourly_budget'], $idle['queued'], $idle['events'],
        ]);
        self::assertSame(240, $idle['hourly_budget_default']);
        self::assertSame(
            ['pause' => true, 'resume' => false, 'clear_queue' => false, 'set_budget' => true],
            $idle['can']
        );

        $this->queue($event, '00A0');
        $this->queue($event, '00A2', mail: 'confirmed', causeKey: 'c2');
        $other = $this->published();
        $this->queue($other, '00A0');
        $this->transition(new UnpublishEvent(), $other);
        $this->storeState([
            'paused_at' => '2026-11-24 08:00:00.000000',
            'cause' => 'errors',
            'delivery_id' => $this->deliveryIds()[0],
            'delivery_event_id' => $event,
            'error' => 'send wp_mail_failed: SMTP Error: x',
            'errors_in_row' => 2,
            'hourly_budget' => 100,
        ]);

        $data = $this->rest('GET', '/mail')->get_data();

        self::assertSame([
            'cause' => 'errors',
            'since' => '2026-11-24T08:00:00Z',
            'by' => null,
            'delivery' => ['id' => $this->deliveryIds()[0], 'event_id' => $event],
            'error' => 'send wp_mail_failed: SMTP Error: x',
        ], $data['paused']);
        self::assertSame([2, 100, 3], [$data['errors_in_row'], $data['hourly_budget'], $data['queued']]);
        self::assertSame(
            [
                ['event_id' => $event, 'name' => $this->nameOf($event), 'held' => false, 'queued' => 2, 'sent' => 0,
                    'failed' => 0, 'with_error' => 0, 'stuck_sending' => 0, 'can' => ['clear_event' => false]],
                ['event_id' => $other, 'name' => $this->nameOf($other), 'held' => true, 'queued' => 1, 'sent' => 0,
                    'failed' => 0, 'with_error' => 0, 'stuck_sending' => 0, 'can' => ['clear_event' => true]],
            ],
            $data['events']
        );
        self::assertSame(240, $data['hourly_budget_default'], 'The default stays when the budget is changed.');
        self::assertSame(
            ['pause' => false, 'resume' => true, 'clear_queue' => true, 'set_budget' => true],
            $data['can']
        );
    }

    public function testAPauseByHandNamesThePersonAndHasNoDelivery(): void
    {
        $this->exec(new PauseMail(), []);
        $this->as($this->admin->userId);

        $paused = $this->rest('GET', '/mail')->get_data()['paused'];

        self::assertSame('manual', $paused['cause']);
        self::assertSame($this->admin->userId, $paused['by']['id']);
        self::assertSame(get_userdata($this->admin->userId)->display_name, $paused['by']['name']);
        self::assertNull($paused['delivery']);
        self::assertNull($paused['error']);
    }

    public function testTheTroubledListHasCodesAndTheStoredErrorOnly(): void
    {
        [$event] = $this->ready();
        $this->queue($event, '00A0');
        $this->queue($event, '00A2', MailKind::Manual, 'manual', '12');
        $this->queue($event, '00A5', causeKey: 'c3');
        [$one, $two] = $this->deliveryIds();
        global $wpdb;
        $table = $wpdb->prefix . 'kalaka_deliveries';
        $wpdb->update($table, [
            'error' => 'send wp_mail_failed: SMTP Error: x',
            'attempts' => 2,
            'claimed_at' => '2026-11-24 07:55:00.000000',
        ], ['id' => $one]);
        $wpdb->update(
            $table,
            ['status' => 'failed', 'error' => 'invalid_address', 'cause_type' => 'mail_batch'],
            ['id' => $two]
        );
        $this->as($this->admin->userId);

        $rows = $this->rest('GET', '/events/' . $event . '/mail/troubled')->get_data();

        self::assertCount(2, $rows);
        self::assertSame([
            'id' => $one, 'code' => '00A0', 'mail' => 'registered', 'label' => 'Jelentkezés rögzítve',
            'kind' => 'action', 'status' => 'pending', 'attempts' => 2,
            'queued_at' => '2026-11-30T09:00:30Z', 'last_attempt_at' => '2026-11-24T07:55:00Z',
            'error' => 'send wp_mail_failed: SMTP Error: x', 'batch_id' => null,
        ], $rows[0]);
        self::assertSame(
            ['manual', null, 'manual', 'failed', null, 12],
            [$rows[1]['mail'], $rows[1]['label'], $rows[1]['kind'], $rows[1]['status'],
                $rows[1]['last_attempt_at'], $rows[1]['batch_id']]
        );
    }

    public function testTheBatchesAreTheBatchesOwnRecordAndOneBatchHasItsMembers(): void
    {
        [$event, $body] = $this->ready();
        $this->registration($event, '00A8', status: 'withdrawn');
        $this->as($this->admin->userId);
        $first = $this->rest('POST', '/events/' . $event . '/mail/manual/preview', ['codes' => ['00A0']]);
        self::assertSame(200, $first->get_status());
        self::assertSame([], $this->rest('GET', '/events/' . $event . '/mail/batches')->get_data());
        $sent = (new \Kalaka\Mail\Api\ManualMails())->send(
            ['event_id' => $event, 'body_id' => $body, 'codes' => ['00A0', '00A2', '00A8']],
            $this->admin,
            $this->runner
        );

        $list = $this->rest('GET', '/events/' . $event . '/mail/batches')->get_data();
        $one = $this->rest('GET', '/events/' . $event . '/mail/batches/' . $sent['batch_id'])->get_data();

        self::assertCount(1, $list);
        self::assertSame([
            'id' => $sent['batch_id'], 'body_id' => $body, 'subject' => 'Sikeres jelentkezés', 'body_gone' => false,
            'layout' => true,
            'created_at' => '2026-11-30T09:00:30Z',
            'created_by' => ['id' => $this->admin->userId, 'name' => get_userdata($this->admin->userId)->display_name],
            'queued' => 2, 'skipped' => 1,
        ], $list[0]);
        self::assertSame($list[0] + ['members' => [
            ['code' => '00A0', 'outcome' => 'queued'],
            ['code' => '00A2', 'outcome' => 'queued'],
            ['code' => '00A8', 'outcome' => 'skipped_withdrawn'],
        ]], $one);
        self::assertArrayNotHasKey('request_key', $one);
    }

    public function testThePreviewShowsThePlanAndTheBodiesAndWritesNothing(): void
    {
        [$event, $body] = $this->ready();
        $this->registration($event, '00A8', status: 'withdrawn');
        wp_insert_post([
            'post_type' => 'kalaka_mail', 'post_status' => 'draft', 'post_title' => 'Vázlat',
            'meta_input' => ['kalaka_event_id' => $event],
        ]);
        $this->as($this->admin->userId);

        $response = $this->rest('POST', '/events/' . $event . '/mail/manual/preview', [
            'codes' => ['00A0', '00A8', '00A5'],
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame([
            'queue' => ['00A0'],
            'skipped' => [['code' => '00A8', 'reason' => 'withdrawn']],
            'unknown' => ['00A5'],
            'bodies' => [['id' => $body, 'subject' => 'Sikeres jelentkezés']],
        ], $response->get_data());
        self::assertSame([], $this->deliveries());
    }

    public function testThePreviewRefusesTheCodesTheSchemaRefuses(): void
    {
        [$event] = $this->ready();
        $this->as($this->admin->userId);
        $route = '/events/' . $event . '/mail/manual/preview';

        self::assertSame(400, $this->rest('POST', $route, ['codes' => []])->get_status());
        self::assertSame(400, $this->rest('POST', $route, [])->get_status());
        self::assertSame(400, $this->rest('POST', $route, ['codes' => ['00A0', '00A0']])->get_status());
    }

    public function testAGrantHolderPreviewsInsideTheViewAndGetsTheSameRefusalForAnUnknownAndAnOutsideCode(): void
    {
        [$event, $body] = $this->ready();
        $this->registration($event, '00A8', status: 'withdrawn');
        $this->as($this->holder($event));
        $route = '/events/' . $event . '/mail/manual/preview';

        $inside = $this->rest('POST', $route, ['codes' => ['00A0', '00A2']]);
        self::assertSame(200, $inside->get_status());
        self::assertSame(['00A0', '00A2'], $inside->get_data()['queue']);
        self::assertSame([], $inside->get_data()['unknown']);
        self::assertSame([['id' => $body, 'subject' => 'Sikeres jelentkezés']], $inside->get_data()['bodies']);

        $refusals = [];
        foreach (['00A5', '00A8'] as $code) {
            $response = $this->rest('POST', $route, ['codes' => ['00A0', $code]]);
            $data = $response->get_data();
            self::assertArrayNotHasKey('unknown', $data);
            self::assertArrayNotHasKey('bodies', $data);
            $refusals[] = [$response->get_status(), $data['code'] ?? null, $data['message'] ?? null];
        }
        self::assertSame(403, $refusals[0][0]);
        self::assertSame($refusals[0], $refusals[1]);
    }

    public function testThePreviewChecksAccessBeforeItLooksForTheEvent(): void
    {
        [$event] = $this->ready();
        $this->as($this->holder($event));
        $codes = ['codes' => ['00A0']];
        self::assertSame(403, $this->rest('POST', '/events/99999/mail/manual/preview', $codes)->get_status());

        $this->as(self::factory()->user->create(['role' => 'subscriber']));
        $route = '/events/' . $event . '/mail/manual/preview';
        self::assertSame(403, $this->rest('POST', $route, $codes)->get_status());

        $this->as($this->admin->userId);
        self::assertSame(404, $this->rest('POST', '/events/99999/mail/manual/preview', $codes)->get_status());
    }
}
