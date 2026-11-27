<?php

declare(strict_types=1);

namespace Kalaka\Admin\Mail;

use Kalaka\Events\Api\Event;
use Kalaka\Mail\Api\ActionMailSetting;
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

/**
 * The event's „Levelek” page, rendered in PHP (owner 2026-11-27, walkthrough 8; section 8 of the build list): no
 * tabs. Top: the „Áttekintés” card — the event's counts, its failures, the clear act, the held notice and the group
 * mails with their members in a dialog —, then „Küldési pontok” and „Hozzá nem rendelt sablonok”; „Levélkeret” is
 * the same admin page with `&view=layout`. Every act and every read is the server's: this class only lays out what
 * `Mail\Api` reports, so no rule is copied here. The client does only the dynamic parts (`app/mail/event-page.js`):
 * the switch, the choose dialog, the X, the group-mail details and the clear. Words are `Texts`' constants.
 *
 * Today's branch lacks three shapes the section-8 plan adds to the read — `template`,
 * `enable_unavailable`/`can.enable` and `wired`. The page renders the template from the assigned `body_id` and the
 * `bodies` list instead, keeps the switch's own `refusals` for its disabled state, shows every point (no `wired`
 * filter), and disables „Új sablon”, whose route is not on the branch either. [UNAPPROVED N1280 N1281 N1282]
 */
final class EventPage
{
    /** The overview, the points, the unassigned list, or the levélkeret screen. */
    public static function render(Event $event): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        if ($view === 'layout') {
            return self::layout($event);
        }

        $data = self::data($event);
        $html = '<div class="wrap kalaka-mail-wrap"><h1>' . esc_html($event->name) . '</h1>';
        $html .= '<p><a class="button" href="' . esc_url(self::layoutUrl($event)) . '">'
            . esc_html(Texts::LAYOUT_LINK) . '</a></p>';
        if ($data['held']) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html(Texts::HELD) . '</p></div>';
        }
        $html .= self::overview($event, $data);
        $html .= self::points($event, $data);
        $html .= self::unassigned($data);

        return $html . '</div>';
    }

    /**
     * `window.kalakaMailPage`: the event, the bodies of its choose dialog and the dialog words.
     *
     * @return array{event: int, rest: string, bodies: list<array{id: int, label: string}>,
     *     texts: array<string, string>, urls: array<string, string>}
     */
    public static function config(Event $event): array
    {
        $data = self::data($event);

        return [
            'event' => $event->id,
            'rest' => '/kalaka-admin/v1',
            'bodies' => array_map(static fn (MailBodyInfo $body): array => [
                'id' => $body->id,
                'label' => self::bodyLabel($body, $data['bodies']),
            ], array_values(array_filter(
                $data['bodies'],
                static fn (MailBodyInfo $body): bool => $body->status === 'publish'
            ))),
            'texts' => [
                'chooseTitle' => Texts::CHOOSE_TEMPLATE,
                'choose' => Texts::CHOOSE_TEMPLATE,
                'cancel' => Texts::CANCEL,
                'close' => Texts::CLOSE,
                'clearConfirm' => Texts::CLEAR_CONFIRM,
                'clear' => Texts::CLEAR_QUEUED,
                'actFail' => Texts::ACT_FAIL,
                'batchTitle' => Texts::BATCH_OPEN,
            ],
            'urls' => [
                'layout' => self::layoutUrl($event),
            ],
        ];
    }

    /**
     * The overview card: counts, the clear act, the failures, the group mails with their details.
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function overview(Event $event, array $data): string
    {
        $stats = $data['stats'];
        $html = '<div class="card" style="max-width:100%">';
        $html .= '<h2>' . esc_html(Texts::OVERVIEW_TITLE) . '</h2>';
        $html .= '<ul class="kalaka-mail-counts">';
        $html .= '<li>' . esc_html(Texts::QUEUED) . ': <strong>' . (int) $stats->queued . '</strong></li>';
        $html .= '<li>' . esc_html(Texts::SENT) . ': <strong>' . (int) $stats->sent . '</strong></li>';
        $html .= '<li>' . esc_html(Texts::FAILED) . ': <strong>' . (int) $stats->failed . '</strong></li>';
        $html .= '</ul>';
        if ($data['canClear']) {
            $html .= '<p><button type="button" class="button" data-kalaka-clear-event="' . (int) $event->id . '">'
                . esc_html(Texts::CLEAR_QUEUED) . '</button></p>';
        }
        $html .= self::troubled($data['troubled'], $event);
        $html .= self::batches($data['batches']);

        return $html . '</div>';
    }

    /**
     * The event's failures: what is failed, waiting after an error, or stuck in sending.
     *
     * @param list<TroubledDelivery> $troubled the event's deliveries that need a look
     */
    private static function troubled(array $troubled, Event $event): string
    {
        if ($troubled === []) {
            return '';
        }
        $html = '<h3>' . esc_html(Texts::TROUBLED_TITLE) . '</h3>';
        $html .= self::tableOpen();
        $html .= '<table class="widefat striped"><thead><tr>';
        foreach ([Texts::TROUBLED_CODE, Texts::TROUBLED_MAIL, Texts::TROUBLED_STATUS, Texts::TROUBLED_ERROR] as $word) {
            $html .= '<th scope="col">' . esc_html($word) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($troubled as $delivery) {
            /** @var TroubledDelivery $delivery */
            $html .= '<tr>';
            $html .= '<td><code>' . esc_html($delivery->code) . '</code></td>';
            $label = $delivery->action !== null
                ? $delivery->action->label()
                : ($delivery->batchId === null
                    ? $delivery->mail
                    : sprintf(Texts::BATCH_MANUAL, $delivery->batchId));
            $html .= '<td>' . esc_html($label) . '</td>';
            $html .= '<td>' . esc_html(self::deliveryStatus($delivery->status)) . '</td>';
            $html .= '<td>' . esc_html((string) $delivery->error) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= self::tableClose();

        // The full list, with the follow-up on each failed row, is the site page's. [UNAPPROVED N1283]
        $html .= '<p><a href="' . esc_url(admin_url('admin.php?page=' . MailPages::siteSlug())) . '">'
            . esc_html(Texts::SENDER_LINK) . '</a></p>';

        return $html;
    }

    /**
     * The group mails: one row each, the members in a dialog.
     *
     * @param list<BatchInfo> $batches the event's send batches
     */
    private static function batches(array $batches): string
    {
        if ($batches === []) {
            return '';
        }
        $html = '<h3>' . esc_html(Texts::BATCHES_TITLE) . '</h3>';
        $html .= self::tableOpen();
        $html .= '<table class="widefat striped"><thead><tr>';
        foreach ([Texts::BATCH_NUMBER, Texts::BATCH_SUBJECT, Texts::BATCH_QUEUED, Texts::BATCH_SKIPPED] as $word) {
            $html .= '<th scope="col">' . esc_html($word) . '</th>';
        }
        $html .= '<th scope="col"><span class="screen-reader-text">'
            . esc_html(Texts::BATCH_DETAILS_LABEL) . '</span></th>';
        $html .= '</tr></thead><tbody>';
        foreach ($batches as $batch) {
            /** @var BatchInfo $batch */
            $html .= '<tr>';
            $html .= '<td>' . (int) $batch->id . '</td>';
            $html .= '<td>' . esc_html((string) $batch->subject) . '</td>';
            $html .= '<td>' . (int) $batch->queued . '</td>';
            $html .= '<td>' . (int) $batch->skipped . '</td>';
            $html .= '<td><button type="button" class="button" data-kalaka-batch="' . (int) $batch->id . '">'
                . esc_html(sprintf(Texts::BATCH_OPEN, $batch->id)) . '</button></td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>' . self::tableClose();
    }

    /**
     * „Küldési pontok”: the point, its template's card or the choose/new buttons, and the switch.
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function points(Event $event, array $data): string
    {
        $html = '<h2>' . esc_html(Texts::SECTION_POINTS) . '</h2>';
        $html .= self::tableOpen();
        $html .= '<table class="widefat striped kalaka-points"><thead><tr>';
        $html .= '<th scope="col">' . esc_html(Texts::POINT_COLUMN) . '</th>';
        $html .= '<th scope="col">' . esc_html(Texts::TEMPLATE_COLUMN) . '</th>';
        $html .= '<th scope="col">' . esc_html(Texts::SWITCH_COLUMN) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['mails'] as $mail) {
            /** @var ActionMailSetting $mail */
            $point = $data['points'][$mail->action->value]
                ?? ['template' => null, 'enable_unavailable' => null, 'wired' => false];
            if (!$point['wired']) {
                continue;
            }
            $switch = $data['switches'][$mail->action->value] ?? [
                'can' => ['enable' => false, 'switch_off' => false],
                'refusals' => ['switch_on' => null, 'switch_off' => null],
            ];
            $allowed = $mail->enabled ? $switch['can']['switch_off'] : $switch['can']['enable'];

            $html .= '<tr>';
            $html .= '<th scope="row">' . esc_html($mail->action->label()) . '</th>';
            $html .= '<td>' . self::templateCell($mail, $point['template'], $data) . '</td>';
            // The switch is simply disabled while it cannot be used, with no standing help text (owner 8.2); a server
            // text shows only after an act was refused (the client's line under it). [UNAPPROVED N1292]
            $html .= '<td>';
            $html .= '<input type="checkbox" data-kalaka-switch="' . esc_attr($mail->action->value) . '"'
                . ' data-kalaka-on="' . ($mail->enabled ? '1' : '0') . '"'
                . ($mail->enabled ? ' checked' : '')
                . ($allowed ? '' : ' disabled')
                . '>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>' . self::tableClose();
    }

    /**
     * One point's template cell: the card, or the two buttons (choose, new).
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function templateCell(ActionMailSetting $mail, ?MailBodyInfo $body, array $data): string
    {
        if ($body === null) {
            $html = '<p>' . esc_html(Texts::NO_TEMPLATE) . '</p>';
            $html .= self::chooseButton($mail);
            $html .= ' ' . self::newButton($mail);

            return $html;
        }

        $html = '<div class="kalaka-template-card">';
        $html .= '<p><strong>' . esc_html($body->subject) . '</strong></p>';
        $html .= '<p>' . esc_html(Texts::TEMPLATE_STATUS) . ': ' . esc_html(self::statusWord($body->status)) . '</p>';
        $html .= '<p>' . esc_html(Texts::USES_FRAME) . ': '
            . esc_html($body->usesFrame ? Texts::YES : Texts::NO) . '</p>';
        $html .= '<p>' . esc_html(Texts::MODIFIED) . ': ' . esc_html(self::moment($body->modified)) . '</p>';
        $html .= '<p>';
        $html .= '<a href="' . esc_url($body->editUrl) . '">' . esc_html(Texts::EDIT) . '</a>';
        $html .= ' <button type="button" class="button-link kalaka-remove-template" data-kalaka-remove="'
            . esc_attr($mail->action->value) . '" aria-label="' . esc_attr(Texts::REMOVE_TEMPLATE) . '">×</button>';
        $html .= '</p>';
        $html .= self::chooseButton($mail);

        return $html . '</div>';
    }

    private static function chooseButton(ActionMailSetting $mail): string
    {
        return '<button type="button" class="button" data-kalaka-choose="' . esc_attr($mail->action->value) . '">'
            . esc_html(Texts::CHOOSE_TEMPLATE) . '</button>';
    }

    /**
     * „Új sablon”: `POST …/mail/points/{mail}/template` makes a draft, assigns it and switches the point off; the
     * client then opens the new template's editor. [UNAPPROVED N1282]
     */
    private static function newButton(ActionMailSetting $mail): string
    {
        return '<button type="button" class="button" data-kalaka-new="' . esc_attr($mail->action->value) . '">'
            . esc_html(Texts::NEW_TEMPLATE) . '</button>';
    }

    /**
     * „Hozzá nem rendelt sablonok”: the event's templates no point uses.
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function unassigned(array $data): string
    {
        $html = '<h2>' . esc_html(Texts::SECTION_UNASSIGNED) . '</h2>';
        $html .= self::tableOpen();
        $html .= '<table class="widefat striped"><thead><tr>';
        foreach ([Texts::TEMPLATE_COLUMN, Texts::TEMPLATE_STATUS, Texts::USES_FRAME, Texts::MODIFIED] as $word) {
            $html .= '<th scope="col">' . esc_html($word) . '</th>';
        }
        $html .= '<th scope="col"><span class="screen-reader-text">' . esc_html(Texts::EDIT) . '</span></th>';
        $html .= '</tr></thead><tbody>';
        $any = false;
        foreach ($data['bodies'] as $body) {
            /** @var MailBodyInfo $body */
            if ($body->usedBy !== null) {
                continue;
            }
            $any = true;
            $html .= '<tr>';
            $html .= '<td>' . esc_html($body->subject) . '</td>';
            $html .= '<td>' . esc_html(self::statusWord($body->status)) . '</td>';
            $html .= '<td>' . esc_html($body->usesFrame ? Texts::YES : Texts::NO) . '</td>';
            $html .= '<td>' . esc_html(self::moment($body->modified)) . '</td>';
            $html .= '<td><a href="' . esc_url($body->editUrl) . '">' . esc_html(Texts::EDIT) . '</a></td>';
            $html .= '</tr>';
        }
        if (!$any) {
            $html .= '<tr><td colspan="5">' . esc_html(Texts::NONE_UNASSIGNED) . '</td></tr>';
        }

        return $html . '</tbody></table>' . self::tableClose();
    }

    /**
     * A wide table scrolls inside its own box, so the page itself never scrolls sideways on a phone (measured at
     * 375 px). A wrapper, not a new table style: the core `widefat` table keeps its look. [UNAPPROVED N1291]
     */
    private static function tableOpen(): string
    {
        return '<div style="overflow-x: auto; max-width: 100%;">';
    }

    private static function tableClose(): string
    {
        return '</div>';
    }

    /** „Levélkeret”: a plain code editor on half the screen; the other half is the roadmap's preview. */
    private static function layout(Event $event): string
    {
        $data = self::data($event);
        $html = '<div class="wrap kalaka-mail-wrap"><h1>' . esc_html(Texts::LAYOUT_TITLE) . '</h1>';
        $html .= '<p><a href="' . esc_url(self::pageUrl($event)) . '">' . esc_html(Texts::BACK_TO_PAGE) . '</a></p>';
        $html .= '<div style="max-width:50%">';
        $html .= '<p><label for="kalaka-mail-layout">' . esc_html(Texts::LAYOUT_FIELD) . '</label></p>';
        $html .= '<textarea id="kalaka-mail-layout" class="large-text code" rows="20" data-kalaka-layout="'
            . (int) $event->id . '">' . esc_textarea($data['layoutHtml']) . '</textarea>';
        $html .= '<p><button type="button" class="button button-primary" data-kalaka-save-layout>'
            . esc_html(Texts::SAVE) . '</button></p>';
        $html .= '</div>';

        return $html . '</div>';
    }

    /**
     * Every read the page lays out, one round: the settings, the bodies and their facts, the counts, the queues.
     *
     * @return array{mails: list<ActionMailSetting>, layoutHtml: string, bodies: list<MailBodyInfo>,
     *     bodiesById: array<int, MailBodyInfo>,
     *     points: array<string, array{template: ?MailBodyInfo, enable_unavailable: ?string, wired: bool}>,
     *     switches: array<string, array{can: array{switch_on: bool, switch_off: bool},
     *         refusals: array{switch_on: ?array{code: string, message: string},
     *         switch_off: ?array{code: string, message: string}}}>,
     *     stats: EventMailStats, held: bool, troubled: list<TroubledDelivery>,
     *     batches: list<BatchInfo>, canClear: bool}
     */
    private static function data(Event $event): array
    {
        $actions = new ActionMailSettings();
        $settings = $actions->ofEvent($event->id);
        $bodies = (new MailBodies())->ofEvent($event->id);
        $facts = [];
        $bodiesById = [];
        foreach ($bodies as $body) {
            $facts[$body->id] = $body->facts;
            $bodiesById[$body->id] = $body;
        }
        $stats = (new MailStats())->ofEvents([$event->id])[$event->id] ?? new EventMailStats();

        return [
            'mails' => $settings->mails,
            'layoutHtml' => $settings->layoutHtml,
            'bodies' => $bodies,
            'bodiesById' => $bodiesById,
            'points' => self::pointFacts($settings->mails, $bodiesById),
            'switches' => $actions->switchesOf($settings, $event, $facts),
            'stats' => $stats,
            'held' => MailHold::isHeld($event),
            'troubled' => (new Deliveries())->troubled($event->id),
            'batches' => (new ManualMails())->batches($event->id),
            // The clear act is offered only while `ClearEventMail` would run, the same check the route's `can` uses;
            // the use case refuses again. [UNAPPROVED N1284]
            'canClear' => self::mayClear($event),
        ];
    }

    /**
     * The read's per-point facts, built from the same classes the route uses: the template, why a switch-on is not
     * offered, and whether the point really sends (only `wired` ones are shown).
     *
     * @param list<ActionMailSetting> $mails
     * @param array<int, MailBodyInfo> $bodies
     * @return array<string, array{template: ?MailBodyInfo, enable_unavailable: ?string, wired: bool}>
     */
    private static function pointFacts(array $mails, array $bodies): array
    {
        $points = [];
        foreach ($mails as $mail) {
            $body = $mail->bodyId === null ? null : ($bodies[$mail->bodyId] ?? null);
            $reason = null;
            if (!$mail->enabled) {
                $reason = $body === null ? 'no_template' : ($body->facts->published ? null : 'template_draft');
            }
            $points[$mail->action->value] = [
                'template' => $body,
                'enable_unavailable' => $reason,
                'wired' => $mail->action->isWired(),
            ];
        }

        return $points;
    }

    /** @param list<MailBodyInfo> $bodies every body of the event, so a repeated subject gets its number */
    private static function bodyLabel(MailBodyInfo $body, array $bodies): string
    {
        $same = 0;
        foreach ($bodies as $other) {
            if ($other->subject === $body->subject && $other->id <= $body->id) {
                $same++;
            }
        }

        return $same > 1 ? sprintf(Texts::NUMBERED, $body->subject, $same) : $body->subject;
    }

    private static function statusWord(string $status): string
    {
        return match ($status) {
            'publish' => Texts::PUBLISHED,
            'future' => Texts::SCHEDULED,
            'pending' => Texts::PENDING,
            'private' => Texts::PRIVATE_STATUS,
            default => Texts::DRAFT,
        };
    }

    private static function deliveryStatus(string $status): string
    {
        return match ($status) {
            'failed' => Texts::DELIVERY_FAILED,
            'sending' => Texts::DELIVERY_SENDING,
            default => Texts::DELIVERY_PENDING,
        };
    }

    private static function moment(?\DateTimeImmutable $instant): string
    {
        $text = $instant === null ? false : wp_date('Y. m. d. H:i', $instant->getTimestamp(), wp_timezone());

        return $text === false ? Texts::NONE : $text;
    }

    /** The route's own `can.clear_event` rule, asked of the use case rather than copied. */
    private static function mayClear(Event $event): bool
    {
        try {
            ClearEventMail::refuse($event);
        } catch (EventMailNotHeld) {
            return false;
        }

        return true;
    }

    private static function pageUrl(Event $event): string
    {
        return admin_url('admin.php?page=' . MailPages::slugOf($event));
    }

    private static function layoutUrl(Event $event): string
    {
        return add_query_arg('view', 'layout', self::pageUrl($event));
    }
}
