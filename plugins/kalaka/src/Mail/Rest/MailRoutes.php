<?php

declare(strict_types=1);

namespace Kalaka\Mail\Rest;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Api\BatchInfo;
use Kalaka\Mail\Api\Deliveries;
use Kalaka\Mail\Api\EventMailStats;
use Kalaka\Mail\Api\MailBodies;
use Kalaka\Mail\Api\MailBodyInfo;
use Kalaka\Mail\Api\MailHold;
use Kalaka\Mail\Api\MailStats;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Api\TroubledDelivery;
use Kalaka\Mail\ClearEventMail;
use Kalaka\Mail\EventMailNotHeld;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Mail\Pause\MailState;

/**
 * The mail screens' reads under `kalaka-admin/v1`, each through `Boundary::route()`. Delivery only: every rule
 * (what a mail may be switched to, what a clear may do, who a manual mail may reach) is the use case's or the
 * `Api` read's own, and the JSON carries what they say. Times are UTC `Y-m-d\TH:i:s\Z`; a user is
 * `{id, name}`, or null for the system and the command line.
 *
 * The gate of every route is full access, except the preview, whose gate is `Access::mayReachAdmin()` and whose
 * access check (`AdminAccess::check()`) runs inside the read, before anything about the codes. An event's
 * existence is checked after the gate (404).
 *
 * - `GET /events/{id}/mail`: the event's mail page: the action mails with `can` and `refusals` of a switch, the
 *   bodies, the counts and `can.clear_event`.
 * - `GET /mail`: the sender: the pause, the errors in a row, the budget and its default, the pending count and
 *   each event's name, counts and `can.clear_event` (an archived event is listed only while something of it is
 *   queued or failed).
 * - `GET /events/{id}/mail/troubled`: the event's deliveries that need a look, each by registration `code` and
 *   `name` (the display name through the viewer's held view; null without name access).
 * - `GET /events/{id}/mail/batches` and `…/batches/{batch}`: the manual mail's own record, never the deliveries;
 *   a batch's members carry `name` beside `code`.
 * - `POST /events/{id}/mail/manual/preview` with `{selection}`: who a send to the selection would reach. The
 *   selection is `{codes}`, or `{view, grant, filters?, search?}`: every row of the viewer's held view that the
 *   grid's filters and search leave, across all pages, resolved here as the grid's rows route reads them. `count`
 *   is the number of codes the selection names now; `names` maps each queued or skipped code to its display name
 *   through the viewer's held view (null without name access).
 */
final class MailRoutes
{
    public const NAMESPACE = 'kalaka-admin/v1';

    private const EVENT = '/events/(?P<id>[1-9][0-9]*)';

    private const TIME = 'Y-m-d\TH:i:s\Z';

    /** `rest_api_init` */
    public static function register(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'required' => true];
        $full = [self::class, 'permission'];

        Boundary::route(self::NAMESPACE, self::EVENT . '/mail', [
            'methods' => 'GET',
            'callback' => [self::class, 'eventMail'],
            'permission_callback' => $full,
            'args' => ['id' => $id],
        ]);
        Boundary::route(self::NAMESPACE, '/mail', [
            'methods' => 'GET',
            'callback' => [self::class, 'sender'],
            'permission_callback' => $full,
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/mail/troubled', [
            'methods' => 'GET',
            'callback' => [self::class, 'troubled'],
            'permission_callback' => $full,
            'args' => ['id' => $id],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/mail/batches', [
            'methods' => 'GET',
            'callback' => [self::class, 'batches'],
            'permission_callback' => $full,
            'args' => ['id' => $id],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/mail/batches/(?P<batch>[1-9][0-9]*)', [
            'methods' => 'GET',
            'callback' => [self::class, 'batch'],
            'permission_callback' => $full,
            'args' => ['id' => $id, 'batch' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
        // The selection is the use case's own schema (codes, or a view with the grid's filters and search), so a
        // client learns the rule before a send.
        $selection = (new SendManualMail())->inputSchema()['properties']['selection'] ?? [];
        Boundary::route(self::NAMESPACE, self::EVENT . '/mail/manual/preview', [
            'methods' => 'POST',
            'callback' => [self::class, 'preview'],
            'permission_callback' => [self::class, 'permissionToPreview'],
            'args' => ['id' => $id, 'selection' => ['required' => true] + $selection],
        ]);
    }

    /** @throws PermissionDenied without full access */
    public static function permission(): bool
    {
        if (!current_user_can(AdminAccess::FULL_ACCESS)) {
            throw new PermissionDenied();
        }

        return true;
    }

    /**
     * The preview's gate: full access or at least one grant; the read decides for the codes asked.
     *
     * @throws PermissionDenied
     */
    public static function permissionToPreview(): bool
    {
        if (!Access::mayReachAdmin(get_current_user_id())) {
            throw new PermissionDenied();
        }

        return true;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     */
    public static function eventMail(\WP_REST_Request $request): \WP_REST_Response
    {
        $event = self::event($request);
        $eventId = $event->id;
        $settings = new ActionMailSettings();
        $mails = $settings->ofEvent($eventId);
        $bodies = (new MailBodies())->ofEvent($eventId);
        $facts = [];
        foreach ($bodies as $body) {
            $facts[$body->id] = $body->facts;
        }
        $switches = $settings->switchesOf($mails, $event, $facts);
        $stats = (new MailStats())->ofEvents([$eventId])[$eventId] ?? new EventMailStats();

        return new \WP_REST_Response([
            'event_id' => $eventId,
            'held' => MailHold::isHeld($event),
            'layout_html' => $mails->layoutHtml,
            'mails' => array_map(
                static fn (array $mail): array => $mail + $switches[$mail['mail']],
                $mails->toArray()['mails']
            ),
            'bodies' => array_map(static fn (MailBodyInfo $body): array => [
                'id' => $body->id,
                'subject' => $body->subject,
                'status' => $body->status,
                'used_by' => $body->usedBy?->value,
                'modified' => self::time($body->modified),
                'edit_url' => $body->editUrl,
            ], $bodies),
            'stats' => [
                'queued' => $stats->queued,
                'sent' => $stats->sent,
                'failed' => $stats->failed,
                'with_error' => $stats->withError,
                'stuck_sending' => $stats->stuckSending,
            ],
            'can' => ['clear_event' => self::mayClear($event)],
        ]);
    }

    public static function sender(): \WP_REST_Response
    {
        $stats = new MailStats();
        $site = $stats->site();
        $eventIds = $stats->eventIds();
        $sent = MailHold::sentEventIds();
        $found = new Events();
        $events = [];
        foreach ($stats->ofEvents($eventIds) as $eventId => $count) {
            $event = $found->find($eventId);
            // An archived event drops off the list once nothing of it is queued or failed.
            $done = $count->queued + $count->failed === 0;
            if ($event === null || ($event->state() === EventState::Archived && $done)) {
                continue;
            }
            $events[] = [
                'event_id' => $eventId,
                'name' => $event->name,
                'held' => !in_array($eventId, $sent, true),
                'queued' => $count->queued,
                'sent' => $count->sent,
                'failed' => $count->failed,
                'with_error' => $count->withError,
                'stuck_sending' => $count->stuckSending,
                'can' => ['clear_event' => self::mayClear($event)],
            ];
        }
        $paused = $site->paused;

        return new \WP_REST_Response([
            'paused' => $paused === null ? null : [
                'cause' => $paused->cause,
                'since' => self::time($paused->since),
                'by' => self::user($paused->byUserId),
                'delivery' => $paused->deliveryId === null
                    ? null
                    : ['id' => $paused->deliveryId, 'event_id' => $paused->deliveryEventId],
                'error' => $paused->error,
            ],
            'errors_in_row' => $site->errorsInRow,
            'hourly_budget' => $site->hourlyBudget,
            'hourly_budget_default' => MailState::DEFAULT_HOURLY_BUDGET,
            'queued' => $site->queued,
            'events' => $events,
            // State, not refusals: each use case answers false or 0 to a no-op.
            'can' => [
                'pause' => $paused === null,
                'resume' => $paused !== null,
                'clear_queue' => $site->queued > 0,
                'set_budget' => true,
            ],
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     */
    public static function troubled(\WP_REST_Request $request): \WP_REST_Response
    {
        $event = self::event($request);
        $deliveries = (new Deliveries())->troubled($event->id);
        $names = self::names(
            $event->id,
            array_map(static fn (TroubledDelivery $delivery): string => $delivery->code, $deliveries)
        );

        return new \WP_REST_Response(array_map(static fn (TroubledDelivery $delivery): array => [
            'id' => $delivery->id,
            'code' => $delivery->code,
            'name' => $names[$delivery->code] ?? null,
            'mail' => $delivery->mail,
            'label' => $delivery->action?->label(),
            'kind' => $delivery->kind->value,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'queued_at' => self::time($delivery->queuedAt),
            'last_attempt_at' => self::time($delivery->lastAttemptAt),
            'error' => $delivery->error,
            'batch_id' => $delivery->batchId,
        ], $deliveries));
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     */
    public static function batches(\WP_REST_Request $request): \WP_REST_Response
    {
        $event = self::event($request);

        return new \WP_REST_Response(array_map(
            static fn (BatchInfo $batch): array => self::batchData($batch),
            (new ManualMails())->batches($event->id)
        ));
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event or a batch of the event that does not exist
     */
    public static function batch(\WP_REST_Request $request): \WP_REST_Response
    {
        $event = self::event($request);
        $batch = (new ManualMails())->batch($event->id, (int) $request->get_param('batch')) ?? throw new NotFound();
        $names = $batch->members === null
            ? []
            : self::names($event->id, array_column($batch->members, 'code'));

        return new \WP_REST_Response(self::batchData($batch, $names));
    }

    /**
     * Who a send to the selection would reach. The read checks the actor's access to every code first, an unknown
     * code and one outside the view alike; only then does anything about the event come back, the bodies a
     * send may use (published, `{id, subject}`) among it. `names` holds the display name of each queued or
     * skipped code through the viewer's held view (null without name access).
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = (int) $request->get_param('id');
        $plan = (new ManualMails())->preview(
            ['event_id' => $eventId, 'selection' => $request->get_param('selection')],
            new UserActor(get_current_user_id())
        );
        $codes = array_merge($plan['queue'], array_column($plan['skipped'], 'code'));

        return new \WP_REST_Response([
            'queue' => $plan['queue'],
            'skipped' => $plan['skipped'],
            'unknown' => $plan['unknown'],
            'count' => $plan['count'],
            'names' => self::names($eventId, $codes),
            'bodies' => (new MailBodies())->publishedOf($eventId),
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound
     */
    private static function event(\WP_REST_Request $request): Event
    {
        return (new Events())->find((int) $request->get_param('id')) ?? throw new NotFound();
    }

    /** Whether `ClearEventMail` would accept the event now: its own check decides. */
    private static function mayClear(Event $event): bool
    {
        try {
            ClearEventMail::refuse($event);
        } catch (EventMailNotHeld) {
            return false;
        }

        return true;
    }

    /**
     * @param ?array<string, ?string> $names the members' display names by code, when the batch carries members
     * @return array<string, mixed>
     */
    private static function batchData(BatchInfo $batch, ?array $names = null): array
    {
        $data = [
            'id' => $batch->id,
            'body_id' => $batch->bodyId,
            'subject' => $batch->subject,
            'body_gone' => $batch->bodyGone,
            'created_at' => self::time($batch->createdAt),
            'created_by' => self::user($batch->createdBy),
            'queued' => $batch->queued,
            'skipped' => $batch->skipped,
        ];
        if ($batch->members !== null) {
            $data['members'] = array_map(static fn (array $member): array => [
                'code' => $member['code'],
                'name' => $names[$member['code']] ?? null,
                'outcome' => $member['outcome'],
            ], $batch->members);
        }

        return $data;
    }

    /**
     * The display name of each code, through the requesting user's held view (full access: the master view,
     * so every name; a grant holder: their held views, a name only where one lists the name column). A code
     * without a name is null. `ViewQuery::namesOf()` is called in chunks of at most its own limit.
     *
     * @param list<string> $codes
     * @return array<string, ?string>
     */
    private static function names(int $eventId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $actor = new UserActor(get_current_user_id());
        $access = new Access();
        $query = new ViewQuery();
        $views = $access->isFull($actor)
            ? [$access->master($actor, $eventId)]
            : $access->heldViews($actor, $eventId);
        $names = array_fill_keys($codes, null);
        foreach ($views as $view) {
            foreach (array_chunk($codes, ViewQuery::MAX_NAMES) as $chunk) {
                foreach ($query->namesOf($view, $chunk) as $code => $name) {
                    if ($name !== null) {
                        $names[$code] = $name;
                    }
                }
            }
        }

        return $names;
    }

    private static function time(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }

    /** @return ?array{id: int, name: ?string} null for the system and the command line */
    private static function user(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $user = get_userdata($id);

        return ['id' => $id, 'name' => $user === false ? null : $user->display_name];
    }
}
