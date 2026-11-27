<?php

declare(strict_types=1);

namespace Kalaka\Admin\Event;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\Grid\GridPage;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Forms\Api\DateText;

/**
 * The event's „Esemény adatai” page: the start and the end with their hours, as a server-rendered form in
 * wp-admin under the event's own menu. Only full access reaches it (the event write is full access only), and
 * only the two points are on it: the rest of the record belongs to the future event screen (S72). The form
 * posts `admin-post.php` with `action=kalaka_event_times`; the handler reads the current record, replaces the
 * two points and runs the existing `UpdateEvent` with the form's version, so the checks stay in one place.
 * The input is one native `datetime-local` per point: a native control is either empty or complete, and the
 * use case already accepts a local wall time and stores UTC. The page is a page, so it is rendered on the
 * server; it needs no script. [UNAPPROVED N1051, N1052, N1053]
 */
final class TimesPage
{
    /** The page slug: `kalaka-event-{id}-times`. */
    private const SLUG = 'kalaka-event-%d-times';

    /** The `admin-post.php` action and the nonce action of the form. */
    public const ACTION = 'kalaka_event_times';

    /** `admin_menu`, after GridPage's own menu (same priority, added after it). */
    public static function menu(): void
    {
        if (!AdminAccess::userIsFull(get_current_user_id())) {
            return;
        }
        foreach ((new Events())->notArchived() as $event) {
            add_submenu_page(
                sprintf(GridPage::SLUG, $event->id),
                Texts::TITLE,
                Texts::TITLE,
                'read',
                sprintf(self::SLUG, $event->id),
                [self::class, 'render']
            );
        }
    }

    /** The page, through the error boundary, the access checked again. */
    public static function render(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Boundary::render() returns escaped HTML
        echo Boundary::render('the event times page', static fn (): string => self::html());
    }

    /**
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied for a user without full access
     */
    private static function html(): string
    {
        if (!AdminAccess::userIsFull(get_current_user_id())) {
            throw new PermissionDenied();
        }
        $eventId = self::eventId() ?? throw new NotFound();
        $event = (new Events())->find($eventId) ?? throw new NotFound();

        $notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag of the redirect
        if (isset($_GET['kalaka_saved'])) {
            $notice = '<div class="notice notice-success"><p>' . esc_html(Texts::SAVED) . '</p></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag of the redirect
        } elseif (isset($_GET['kalaka_error'])) {
            $notice = '<div class="notice notice-error"><p>' . esc_html(Texts::FAILED) . '</p></div>';
        }

        $current = $event->start === null && $event->end === null
            ? esc_html(Texts::NO_TIMES)
            : esc_html(sprintf(Texts::CURRENT, self::text($event->start), self::text($event->end)));

        return '<div class="wrap"><h1>' . esc_html(Texts::TITLE) . '</h1>'
            . $notice
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="event_id" value="' . esc_attr((string) $eventId) . '">'
            . '<input type="hidden" name="version" value="' . esc_attr((string) $event->version) . '">'
            . '<table class="form-table" role="presentation"><tbody>'
            . self::row('starts_at', Texts::START, self::inputValue($event->start))
            . self::row('ends_at', Texts::END, self::inputValue($event->end))
            . '</tbody></table>'
            . '<p class="description">' . esc_html(Texts::HELP) . '</p>'
            . '<p class="description">' . $current . '</p>'
            . '<p class="submit"><button type="submit" class="button button-primary">'
            . esc_html(Texts::SAVE) . '</button></p>'
            . '</form></div>';
    }

    private static function row(string $name, string $label, string $value): string
    {
        return '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th>'
            . '<td><input type="datetime-local" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '"'
            . ' value="' . esc_attr($value) . '"></td></tr>';
    }

    /** `admin_post_kalaka_event_times`: the current record with the two posted points, through `UpdateEvent`. */
    public static function save(): void
    {
        check_admin_referer(self::ACTION, '_wpnonce');
        $eventId = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        if (!AdminAccess::userIsFull(get_current_user_id())) {
            wp_die(esc_html(Texts::DENIED), '', ['response' => 403]);
        }
        $event = (new Events())->find($eventId);
        if ($event === null) {
            wp_die(esc_html(Texts::FAILED), '', ['response' => 404]);
        }
        $starts = self::posted('starts_at');
        $ends = self::posted('ends_at');
        if ($starts === false || $ends === false) {
            self::back($eventId, 'kalaka_error');
        }

        try {
            self::apply(
                $event,
                $starts,
                $ends,
                isset($_POST['version']) ? (int) $_POST['version'] : $event->version
            );
        } catch (\Throwable $e) {
            Boundary::shown('saving the event times', $e);
            self::back($eventId, 'kalaka_error');
        }

        self::back($eventId, 'kalaka_saved');
    }

    /**
     * The current record with the two points replaced, through `UpdateEvent`: the one place the event's rules
     * stay. The other values are read back from the record, so the form never posts them.
     *
     * @internal used by `save()` and the integration test
     * It runs the registered ability `kalaka/update-event` as the current user (Admin may not reach the Events
     * use case itself), so the ability's own permission check applies too.
     *
     * @throws \RuntimeException carrying the ability's error code when it refuses
     */
    public static function apply(Event $event, ?string $starts, ?string $ends, int $version): void
    {
        $ability = wp_get_ability('kalaka/update-event');
        if ($ability === null) {
            throw new \RuntimeException('kalaka/update-event is not registered');
        }
        $result = $ability->execute([
            'id' => $event->id,
            'version' => $version,
            'name' => $event->name,
            'capacity' => $event->capacity,
            'registration_opens_at' => self::local($event->registrationOpens),
            'registration_closes_at' => self::local($event->registrationCloses),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'points' => array_map(
                static fn ($point): array => [
                    'id' => $point->id,
                    'name' => $point->name,
                    'at' => self::local($point->at),
                ],
                $event->customPoints
            ),
        ]);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());
        }
    }

    /** The posted `datetime-local`, `YYYY-MM-DD HH:MM`, null when empty, false when malformed. */
    private static function posted(string $name): string|null|false
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- `save()` verified the nonce first.
        $raw = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $raw) !== 1) {
            return false;
        }

        return str_replace('T', ' ', $raw);
    }

    /** A stored instant as the input's local value, `YYYY-MM-DDTHH:MM`; an empty string when null. */
    private static function inputValue(?\DateTimeImmutable $at): string
    {
        return $at === null ? '' : $at->setTimezone(wp_timezone())->format('Y-m-d\TH:i');
    }

    /** A stored instant as the use case's local `YYYY-MM-DD HH:MM`; null when null. */
    private static function local(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(wp_timezone())->format('Y-m-d H:i');
    }

    /** An instant as the page's Hungarian text, date and hours. */
    private static function text(?\DateTimeImmutable $at): string
    {
        if ($at === null) {
            return '–';
        }

        $local = $at->setTimezone(wp_timezone());

        return DateText::of($local->format('Y-m-d')) . ' ' . $local->format('H:i');
    }

    /** @return never */
    private static function back(int $eventId, string $flag): void
    {
        wp_safe_redirect(add_query_arg($flag, '1', self::url($eventId)));
        exit;
    }

    private static function url(int $eventId): string
    {
        return admin_url('admin.php?page=' . sprintf(self::SLUG, $eventId));
    }

    /** The event id of the page slug, or null on another page. */
    private static function eventId(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the page slug names the screen
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (preg_match('/\Akalaka-event-([0-9]+)-times\z/', $page, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
