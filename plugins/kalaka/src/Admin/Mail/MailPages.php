<?php

declare(strict_types=1);

namespace Kalaka\Admin\Mail;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grid\GridPage;
use Kalaka\Admin\Grid\Translations;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * The mail screens (prototype, unapproved as a whole): the event's „Levelek” page, a submenu item of the event's
 * own menu item, and the site's „Kaláka › Levelezés” page the paused banner names. Both are empty containers plus
 * a configuration; every read and act goes through `kalaka-admin/v1` and the abilities, so the page decides no
 * access: the reads are full access's, and a visitor without it gets the refusal. [UNAPPROVED N271]
 */
final class MailPages
{
    private const EVENT_SLUG = 'kalaka-event-%d-mail';
    private const SITE_SLUG = 'kalaka-mail';
    private const HANDLE = 'kalaka-admin-mail';
    private const NAMESPACE = 'kalaka-admin/v1';
    /** After core's separator (4), before the events (4.01 and up). */
    private const KALAKA_POSITION = 4.001;
    /** After the last event item (4.01 + n/100 for the first 98 events), before „Bejegyzések” (5). */
    private const SEPARATOR_POSITION = '4.999';

    /**
     * The menu icon: a „K” as a 20×20 SVG data URI. It is not given to WordPress, which repaints an SVG icon with JS
     * and flashed black on load in the menu mock; `menuStyle()` draws it as a CSS mask filled with `currentColor`.
     */
    private const LOGO = 'data:image/svg+xml;base64,'
        . 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdC'
        . 'b3g9IjAgMCAyMCAyMCI+PHRleHQgeD0iMTAiIHk9IjE3IiB0ZXh0LWFuY2hv'
        . 'cj0ibWlkZGxlIiBmb250LWZhbWlseT0iSGVsdmV0aWNhLEFyaWFsLHNhbnMt'
        . 'c2VyaWYiIGZvbnQtd2VpZ2h0PSI3MDAiIGZvbnQtc2l6ZT0iMTkiIGZpbGw9'
        . 'ImJsYWNrIj5LPC90ZXh0Pjwvc3ZnPg==';

    /** `admin_menu`, after the grid's own menu so the event's menu item exists. Full access only. */
    public static function menu(): void
    {
        $userId = get_current_user_id();
        if (!Access::mayReachAdmin($userId) || !(new Access())->isFull(new UserActor($userId))) {
            return;
        }
        // A new top-level item with its own submenu: the item lands on the „Levelezés” page and its submenu holds
        // just „Levelezés”, because there is no dashboard yet (owner ruling 8.14; no „Irányítópult” entry). Its icon
        // is the Kaláka logo, as the owner's menu ruling has it (a plain „K” until the logo exists, as in the menu
        // mock), drawn as a CSS mask (`menuStyle()`). Its place is the mock's: right after core's separator and
        // right before the events, which the grid page puts at 4.01 and up. [UNAPPROVED N271]
        add_menu_page(
            Texts::SITE_PAGE,
            Texts::SITE_MENU,
            'read',
            self::SITE_SLUG,
            [self::class, 'render'],
            'none',
            self::KALAKA_POSITION
        );
        add_submenu_page(
            self::SITE_SLUG,
            Texts::SITE_PAGE,
            Texts::SITE_PAGE,
            'read',
            self::SITE_SLUG,
            [self::class, 'render']
        );
        // The separator that closes our section before „Bejegyzések”, as the owner's ruling and the mock have it; the
        // approved menu code (#204) puts the events at 4.01 and up and no separator after them. [UNAPPROVED N271]
        global $menu;
        $menu[self::SEPARATOR_POSITION] = ['', 'read', 'separator-kalaka', '', 'wp-menu-separator'];
        foreach ((new Events())->notArchived() as $event) {
            $parent = GridPage::slug($event);
            // The event's mail page, next to „Jelentkezők”.
            add_submenu_page(
                $parent,
                Texts::EVENT_PAGE,
                Texts::EVENT_PAGE,
                'read',
                self::slug($event),
                [self::class, 'render']
            );
        }
    }

    /**
     * `admin_head`: the Kaláka item's icon as a mask filled with the menu's own icon colour, so it follows the colour
     * scheme, hover and current states with no repaint (as the approved menu mock draws its icons).
     */
    public static function menuStyle(): void
    {
        $present = false;
        foreach ((array) ($GLOBALS['menu'] ?? []) as $item) {
            if (is_array($item) && ($item[2] ?? '') === self::SITE_SLUG) {
                $present = true;
            }
        }
        if (!$present) {
            return;
        }
        $icon = esc_attr(self::LOGO);
        $css = '#toplevel_page_' . self::SITE_SLUG . ' div.wp-menu-image::before { content: ""; display: block; '
            . 'width: 20px; height: 20px; margin: 7px auto 0; padding: 0; background-color: currentColor; '
            . '-webkit-mask: url("' . $icon . '") center / 20px 20px no-repeat; '
            . 'mask: url("' . $icon . '") center / 20px 20px no-repeat; }';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constants and an escaped constant
        echo '<style>' . $css . '</style>';
    }

    /** `admin_enqueue_scripts`: the app's script and styles on the two mail pages only. */
    public static function enqueue(): void
    {
        if (self::page() === null) {
            return;
        }
        $built = dirname(KALAKA_FILE) . '/build/Admin/app/';
        if (!is_file($built . 'mail.asset.php')) {
            return;
        }
        /** @var array{dependencies: list<non-empty-string>, version: string} $asset */
        $asset = require $built . 'mail.asset.php';
        $src = plugins_url('build/Admin/app/mail.js', KALAKA_FILE);
        if ($src === '') {
            return;
        }
        wp_register_script(
            self::HANDLE,
            $src,
            $asset['dependencies'],
            $asset['version'],
            true
        );
        // DataViews' stylesheet is one shared chunk, named after the first entry that imports it (the grid's).
        wp_register_style(
            self::HANDLE . '-dataviews',
            plugins_url('build/Admin/app/style-grid.css', KALAKA_FILE),
            ['wp-components'],
            $asset['version']
        );
        wp_enqueue_style(
            self::HANDLE,
            plugins_url('build/Admin/app/mail.css', KALAKA_FILE),
            [self::HANDLE . '-dataviews'],
            $asset['version']
        );
        Translations::load(self::HANDLE);
    }

    /** The page, through the error boundary. */
    public static function render(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Boundary::render() returns escaped HTML
        echo Boundary::render('the mail page', static fn (): string => self::html());
    }

    /**
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied without full access
     */
    private static function html(): string
    {
        $userId = get_current_user_id();
        if (!(new Access())->isFull(new UserActor($userId))) {
            throw new PermissionDenied();
        }
        $page = self::page() ?? throw new NotFound();
        $config = [
            'rest' => '/' . self::NAMESPACE,
            'adminUrl' => admin_url(),
            'timezone' => wp_timezone_string(),
        ];
        if ($page === 0) {
            // The names of the events the page can name; an event the sender read lists and this does not (an
            // archived one) shows as its number.
            $config['kind'] = 'sender';
            $config['events'] = array_map(static fn (Event $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'url' => admin_url('admin.php?page=' . self::slug($event)),
            ], (new Events())->notArchived());
            $head = '<div class="wrap kalaka-mail-wrap"><h1>' . esc_html(Texts::SITE_PAGE) . '</h1>';
        } else {
            $event = (new Events())->find($page) ?? throw new NotFound();
            $config['kind'] = 'event';
            $config['event'] = $event->id;
            $config['eventName'] = $event->name;
            $config['registrantsUrl'] = admin_url('admin.php?page=' . GridPage::slug($event));
            $config['senderUrl'] = admin_url('admin.php?page=' . self::SITE_SLUG);
            $head = '<div class="wrap kalaka-mail-wrap"><h1>' . esc_html($event->name) . '</h1>';
        }
        wp_add_inline_script(self::HANDLE, 'window.kalakaMail = ' . wp_json_encode($config) . ';', 'before');
        wp_enqueue_script(self::HANDLE);

        return $head . '<div id="kalaka-mail"></div></div>';
    }

    /** The event's id, 0 for the site page, null for any other page. */
    private static function page(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === self::SITE_SLUG) {
            return 0;
        }

        return preg_match('/^kalaka-event-([1-9][0-9]*)-mail$/', $page, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The address of the event's „Levelek” page on the bodies tab, or the site's „Levelezés” page without an event;
     * the page an archived or unknown event has no menu item for falls back to the site's.
     */
    public static function url(?int $eventId): string
    {
        foreach ($eventId === null ? [] : (new Events())->notArchived() as $event) {
            if ($event->id === $eventId) {
                return admin_url('admin.php?page=' . self::slug($event) . '&tab=bodies');
            }
        }

        return admin_url('admin.php?page=' . self::SITE_SLUG);
    }

    private static function slug(Event|int $event): string
    {
        return sprintf(self::EVENT_SLUG, $event instanceof Event ? $event->id : $event);
    }
}
