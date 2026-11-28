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
 * tabs. The „Áttekintés” box (the figures in one row, the failures and the group mails each behind a window, the
 * clear act), „Küldési pontok”, „Hozzá nem rendelt sablonok”, then „Levélkeret”, which opens the same admin page with
 * `&view=layout`. Every act and every read is the server's: this class only lays out what `Mail\Api` reports, so no
 * rule is copied here. The client does only the dynamic parts (`app/mail/event-page.js`): the switch, the choose
 * dialog, the X, the two new-template buttons, the two windows, the clear and the layout save. Words are `Texts`'
 * constants. [UNAPPROVED N1280 N1296]
 *
 * The look is WordPress's own (the owner's 2026-11-28 rework): the page's thin stylesheet
 * (`app/mail/event-page.scss`, built from `@wordpress/base-styles`), the components' toggle and icon-button markup,
 * core's `.button`; below 782 px every table row stacks, cell under cell with its column name. [UNAPPROVED N1294]
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
        $html = '<div class="wrap kalaka-mail-wrap kalaka-mail-event"><h1>' . esc_html($event->name) . '</h1>';
        if ($data['held']) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html(Texts::HELD) . '</p></div>';
        }
        $html .= self::overview($event, $data);
        $html .= self::points($data);
        $html .= self::unassigned($data);
        $html .= self::frame($event);

        return $html . '</div>';
    }

    /**
     * `window.kalakaMailPage`: the event, the bodies of its choose dialog, the group mails of their window and the
     * words of the dialogs and windows.
     *
     * @return array{event: int, rest: string, bodies: list<array{id: int, label: string}>,
     *     batches: list<array{id: int, subject: string, queued: int, skipped: int}>,
     *     texts: array<string, mixed>, urls: array<string, string>}
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
            'batches' => array_map(static fn (BatchInfo $batch): array => [
                'id' => $batch->id,
                'subject' => (string) $batch->subject,
                'queued' => $batch->queued,
                'skipped' => $batch->skipped,
            ], $data['batches']),
            'texts' => [
                'chooseTitle' => Texts::CHOOSE_TEMPLATE,
                'choose' => Texts::CHOOSE_TEMPLATE,
                'cancel' => Texts::CANCEL,
                'close' => Texts::CLOSE,
                'clearConfirm' => Texts::CLEAR_CONFIRM,
                'clear' => Texts::CLEAR_QUEUED,
                'actFail' => Texts::ACT_FAIL,
                'loading' => Texts::LOADING,
                'details' => Texts::DETAILS,
                'back' => Texts::BACK_TO_BATCHES,
                'batchesTitle' => Texts::BATCHES_TITLE,
                'batchTitle' => Texts::BATCH_OPEN,
                'batchManual' => Texts::BATCH_MANUAL,
                'batchColumns' => [
                    Texts::BATCH_NUMBER,
                    Texts::BATCH_SUBJECT,
                    Texts::BATCH_QUEUED,
                    Texts::BATCH_SKIPPED,
                ],
                'memberColumns' => [Texts::TROUBLED_CODE, Texts::TROUBLED_STATUS],
                'troubledTitle' => Texts::TROUBLED_TITLE,
                'troubledColumns' => [
                    Texts::TROUBLED_CODE,
                    Texts::TROUBLED_MAIL,
                    Texts::TROUBLED_STATUS,
                    Texts::TROUBLED_ERROR,
                ],
                'deliveryStatus' => [
                    'failed' => Texts::DELIVERY_FAILED,
                    'sending' => Texts::DELIVERY_SENDING,
                    'pending' => Texts::DELIVERY_PENDING,
                ],
                'senderLink' => Texts::SENDER_LINK,
            ],
            'urls' => [
                'layout' => self::layoutUrl($event),
                'sender' => admin_url('admin.php?page=' . MailPages::siteSlug()),
            ],
        ];
    }

    /**
     * The „Áttekintés” box: the figures side by side in one row (wrapping on a phone), „Sikertelen” and „Csoportos
     * levelek” each with a „Részletek” button that opens its window, and the clear act on its own line under the
     * row. [UNAPPROVED N1297]
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function overview(Event $event, array $data): string
    {
        $stats = $data['stats'];
        $html = '<div class="kalaka-mail-panel kalaka-mail-overview">';
        $html .= '<h2>' . esc_html(Texts::OVERVIEW_TITLE) . '</h2>';
        $html .= '<ul class="kalaka-mail-figures">';
        $html .= self::figure('queued', Texts::QUEUED, (int) $stats->queued);
        $html .= self::figure('sent', Texts::SENT, (int) $stats->sent);
        // The failures' window: shown while the event has a delivery that needs a look (failed, waiting after an
        // error, or stuck in sending), the same list the old table showed; the figure counts only the failed ones.
        // [UNAPPROVED N1299]
        $html .= self::figure(
            'failed',
            Texts::FAILED,
            (int) $stats->failed,
            $data['troubled'] === [] ? '' : 'data-kalaka-troubled',
            Texts::TROUBLED_TITLE
        );
        // The group mails: their count, and their list in a window; no button while there is none. [UNAPPROVED N1298]
        $html .= self::figure(
            'batches',
            Texts::BATCHES_TITLE,
            count($data['batches']),
            $data['batches'] === [] ? '' : 'data-kalaka-batches',
            Texts::BATCHES_TITLE
        );
        $html .= '</ul>';
        if ($data['canClear']) {
            $html .= '<p><button type="button" class="button button-compact" data-kalaka-clear-event="'
                . (int) $event->id . '">'
                . esc_html(Texts::CLEAR_QUEUED) . '</button></p>';
        }

        return $html . '</div>';
    }

    /**
     * One figure: „label: number”, and the button of its window when `$opens` names one. The button's accessible
     * name is its own word and the window's title („Részletek: Küldési hibák”, the lektor's form).
     */
    private static function figure(string $key, string $label, int $number, string $opens = '', string $window = ''): string
    {
        $id = 'kalaka-figure-' . $key;
        $html = '<li><span id="' . esc_attr($id) . '">' . esc_html($label) . '</span>: <strong>' . $number
            . '</strong>';
        if ($opens !== '') {
            $html .= ' <button type="button" class="button button-compact" id="' . esc_attr($id . '-open') . '" '
                . esc_attr($opens) . ' aria-label="' . esc_attr(Texts::DETAILS . ': ' . $window) . '">'
                . esc_html(Texts::DETAILS) . '</button>';
        }

        return $html . '</li>';
    }

    /**
     * „Küldési pontok”: one line per point: the point, its template's subject, status, levélkeret use and last
     * change, the switch, and the acts. A filled point offers „Szerkesztés” and the X; an empty one „Sablon
     * kiválasztása” and „Új sablon” (the owner's flow: after the X, the two buttons again). [UNAPPROVED N1300]
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function points(array $data): string
    {
        $html = '<h2>' . esc_html(Texts::SECTION_POINTS) . '</h2>';
        $html .= '<div class="kalaka-mail-panel kalaka-mail-panel--table">';
        $html .= '<table class="kalaka-mail-table kalaka-mail-points"><thead><tr>';
        $columns = [Texts::POINT_COLUMN, Texts::TEMPLATE_COLUMN, Texts::TEMPLATE_STATUS, Texts::USES_FRAME,
            Texts::MODIFIED, Texts::SWITCH_COLUMN];
        foreach ($columns as $word) {
            $html .= '<th scope="col">' . esc_html($word) . '</th>';
        }
        $html .= '<th scope="col" class="kalaka-mail-acts"></th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['mails'] as $mail) {
            /** @var ActionMailSetting $mail */
            $point = $data['points'][$mail->action->value] ?? ['template' => null, 'wired' => false];
            if (!$point['wired']) {
                continue;
            }
            $body = $point['template'];
            $html .= '<tr>';
            $html .= '<th scope="row">' . esc_html($mail->action->label()) . '</th>';
            if ($body === null) {
                $html .= self::cell(Texts::TEMPLATE_COLUMN, esc_html(Texts::NO_TEMPLATE));
                $html .= self::cell(Texts::TEMPLATE_STATUS, esc_html(Texts::NONE));
                $html .= self::cell(Texts::USES_FRAME, esc_html(Texts::NONE));
                $html .= self::cell(Texts::MODIFIED, esc_html(Texts::NONE));
            } else {
                $html .= self::templateCells($body);
            }
            $html .= self::cell(Texts::SWITCH_COLUMN, self::toggle($mail, $data));
            $html .= '<td class="kalaka-mail-acts"><span class="kalaka-mail-buttons">'
                . ($body === null
                    ? self::chooseButton($mail) . self::newButton($mail)
                    : self::editButton($body) . self::removeButton($mail))
                . '</span></td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * A data cell with its column's name, which a phone shows in front of the value (the row stacks).
     * [UNAPPROVED N1295]
     *
     * @param string $html the cell's content, already escaped
     */
    private static function cell(string $column, string $html): string
    {
        return '<td data-colname="' . esc_attr($column) . '">' . $html . '</td>';
    }

    /** A template's four cells: the subject (or its number when it has none), status, levélkeret use, last change. */
    private static function templateCells(MailBodyInfo $body): string
    {
        return self::cell(Texts::TEMPLATE_COLUMN, esc_html(self::subject($body)))
            . self::cell(Texts::TEMPLATE_STATUS, esc_html(self::statusWord($body->status)))
            . self::cell(Texts::USES_FRAME, esc_html($body->usesFrame ? Texts::YES : Texts::NO))
            . self::cell(Texts::MODIFIED, esc_html(self::moment($body->modified)));
    }

    /** A template with no subject yet (a fresh draft) is named by its number: „22. levélsablon”. [UNAPPROVED N1306] */
    private static function subject(MailBodyInfo $body): string
    {
        return trim($body->subject) === '' ? sprintf(Texts::UNTITLED_TEMPLATE, $body->id) : $body->subject;
    }

    /**
     * The point's switch: the components' toggle, printed here, named by the point („Jelentkezés rögzítve”). It is
     * simply disabled while it cannot be used, with no standing help text (owner 8.2); a server text shows only after
     * an act was refused. [UNAPPROVED N1292 N1301]
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function toggle(ActionMailSetting $mail, array $data): string
    {
        $switch = $data['switches'][$mail->action->value] ?? [
            'can' => ['enable' => false, 'switch_off' => false],
        ];
        $allowed = $mail->enabled ? $switch['can']['switch_off'] : $switch['can']['enable'];
        $classes = 'components-form-toggle' . ($mail->enabled ? ' is-checked' : '') . ($allowed ? '' : ' is-disabled');

        return '<span class="' . $classes . '">'
            . '<input type="checkbox" class="components-form-toggle__input"'
            . ' data-kalaka-switch="' . esc_attr($mail->action->value) . '"'
            . ' aria-label="' . esc_attr($mail->action->label()) . '"'
            . ($mail->enabled ? ' checked' : '')
            . ($allowed ? '' : ' disabled')
            . '>'
            . '<span class="components-form-toggle__track"></span>'
            . '<span class="components-form-toggle__thumb"></span>'
            . '</span>';
    }

    /** „Szerkesztés”: a link to the editor, drawn as core's button. [UNAPPROVED N1303] */
    private static function editButton(MailBodyInfo $body): string
    {
        return '<a class="button button-compact" href="' . esc_url($body->editUrl) . '">' . esc_html(Texts::EDIT)
            . '</a>';
    }

    /**
     * The X: the components' icon button with core's „close” icon; its name and tooltip are „Sablon eltávolítása”.
     * One click removes the template and switches the point off, with no confirm. [UNAPPROVED N1302]
     */
    private static function removeButton(ActionMailSetting $mail): string
    {
        return '<button type="button" class="components-button is-compact has-icon kalaka-mail-remove"'
            . ' data-kalaka-remove="' . esc_attr($mail->action->value) . '"'
            . ' aria-label="' . esc_attr(Texts::REMOVE_TEMPLATE) . '" title="' . esc_attr(Texts::REMOVE_TEMPLATE) . '">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"'
            . ' focusable="false" fill="none" stroke="currentColor" stroke-width="1.5">'
            . '<path d="M7.75 16.25L16.25 7.75M16.25 16.25L7.75 7.75"></path></svg>'
            . '</button>';
    }

    private static function chooseButton(ActionMailSetting $mail): string
    {
        return '<button type="button" class="button button-compact" data-kalaka-choose="'
            . esc_attr($mail->action->value) . '">'
            . esc_html(Texts::CHOOSE_TEMPLATE) . '</button>';
    }

    /**
     * „Új sablon”: `POST …/mail/points/{mail}/template` makes a draft, assigns it and switches the point off; the
     * client then opens the new template's editor. [UNAPPROVED N1282]
     */
    private static function newButton(ActionMailSetting $mail): string
    {
        return '<button type="button" class="button button-compact" data-kalaka-new="'
            . esc_attr($mail->action->value) . '">'
            . esc_html(Texts::NEW_TEMPLATE) . '</button>';
    }

    /**
     * „Hozzá nem rendelt sablonok”: the event's templates no point uses, in the points' template columns, each with
     * „Szerkesztés”. Beside the heading, „Új sablon” makes a draft of the event with no point
     * (`POST …/mail/templates`) and opens its editor. [UNAPPROVED N1304]
     *
     * @param array<string, mixed> $data see `data()`
     */
    private static function unassigned(array $data): string
    {
        $html = '<div class="kalaka-mail-heading"><h2>' . esc_html(Texts::SECTION_UNASSIGNED) . '</h2>'
            . '<button type="button" class="button button-compact" data-kalaka-new-unassigned>'
            . esc_html(Texts::NEW_TEMPLATE) . '</button></div>';
        $html .= '<div class="kalaka-mail-panel kalaka-mail-panel--table">';
        $html .= '<table class="kalaka-mail-table kalaka-mail-unassigned"><thead><tr>';
        foreach ([Texts::TEMPLATE_COLUMN, Texts::TEMPLATE_STATUS, Texts::USES_FRAME, Texts::MODIFIED] as $word) {
            $html .= '<th scope="col">' . esc_html($word) . '</th>';
        }
        $html .= '<th scope="col" class="kalaka-mail-acts"><span class="screen-reader-text">'
            . esc_html(Texts::EDIT) . '</span></th>';
        $html .= '</tr></thead><tbody>';
        $any = false;
        foreach ($data['bodies'] as $body) {
            /** @var MailBodyInfo $body */
            if ($body->usedBy !== null) {
                continue;
            }
            $any = true;
            $html .= '<tr>' . self::templateCells($body);
            $html .= '<td class="kalaka-mail-acts"><span class="kalaka-mail-buttons">' . self::editButton($body)
                . '</span></td>';
            $html .= '</tr>';
        }
        if (!$any) {
            $html .= '<tr><td colspan="5">' . esc_html(Texts::NONE_UNASSIGNED) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * „Levélkeret”: the page's last section, a heading and one ordinary button to the levélkeret screen; nothing
     * about it at the top of the page (owner 2026-11-28: not a main action). [UNAPPROVED N1296]
     */
    private static function frame(Event $event): string
    {
        return '<h2>' . esc_html(Texts::FRAME_SECTION) . '</h2>'
            . '<p><a class="button button-compact" href="' . esc_url(self::layoutUrl($event)) . '">'
            . esc_html(Texts::LAYOUT_LINK) . '</a></p>';
    }

    /**
     * „Levélkeret”: a plain code editor on half the screen (the other half is the roadmap's preview), the whole width
     * below 782 px; no help line (owner 2026-11-28). [UNAPPROVED N1308]
     */
    private static function layout(Event $event): string
    {
        $data = self::data($event);
        $html = '<div class="wrap kalaka-mail-wrap kalaka-mail-event"><h1>' . esc_html(Texts::LAYOUT_TITLE) . '</h1>';
        $html .= '<p><a href="' . esc_url(self::pageUrl($event)) . '">' . esc_html(Texts::BACK_TO_PAGE) . '</a></p>';
        $html .= '<div class="kalaka-mail-layout">';
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
     *     points: array<string, array{template: ?MailBodyInfo, wired: bool}>,
     *     switches: array<string, array{can: array{enable: bool, switch_off: bool},
     *         refusals: array{switch_on: ?array{code: string, message: string},
     *         switch_off: ?array{code: string, message: string}}, enable_unavailable: ?string}>,
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
     * The read's per-point facts, built from the same classes the route uses: the template, and whether the point
     * really sends; only the `wired` points are listed. [UNAPPROVED N1288]
     *
     * @param list<ActionMailSetting> $mails
     * @param array<int, MailBodyInfo> $bodies
     * @return array<string, array{template: ?MailBodyInfo, wired: bool}>
     */
    private static function pointFacts(array $mails, array $bodies): array
    {
        $points = [];
        foreach ($mails as $mail) {
            $points[$mail->action->value] = [
                'template' => $mail->bodyId === null ? null : ($bodies[$mail->bodyId] ?? null),
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
