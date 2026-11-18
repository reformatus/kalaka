<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Rest\GridRoutes;
use Kalaka\Admin\View\ViewList;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * The per-event „Jelentkezők” page with the grid (S37), and, until the admin frame (S72) is built, the
 * menu item that reaches it: one top-level item per event that is not archived and that the user reaches
 * (full access: every one; a grant holder: those they hold a grant on), ordered by start, whose only
 * submenu item is „Jelentkezők”. The page slug is `kalaka-event-{id}-registrations`; S72 replaces this
 * class's menu part and keeps the slug.
 *
 * The page renders an empty container and the app (`build/Admin/app/grid.js`) with its configuration: the
 * tabs (full access: „Jelentkezők”, the master view, then every view of the event; a grant holder: their
 * views), each with its columns, and the tab the URL names (`&view=N`).
 */
final class GridPage
{
    private const SLUG = 'kalaka-event-%d-registrations';
    private const HANDLE = 'kalaka-admin-grid';
    private const PER_PAGE = [25, 50, 100, 500];

    /** `admin_menu` */
    public static function menu(): void
    {
        $userId = get_current_user_id();
        if (!Access::mayReachAdmin($userId)) {
            return;
        }
        $actor = new UserActor($userId);
        $access = new Access();
        $full = $access->isFull($actor);
        $granted = $full ? [] : $access->grantedEventIds($actor);

        $position = 0;
        foreach ((new Events())->notArchived() as $event) {
            if (!$full && !in_array($event->id, $granted, true)) {
                continue;
            }
            $slug = sprintf(self::SLUG, $event->id);
            // WordPress escapes the page title where it prints it; the menu title is printed as given.
            add_menu_page(
                $event->name,
                esc_html($event->name),
                'read',
                $slug,
                [self::class, 'render'],
                '',
                4 + ++$position / 100
            );
            add_submenu_page($slug, Texts::REGISTRANTS, Texts::REGISTRANTS, 'read', $slug, [self::class, 'render']);
        }
    }

    /** `admin_enqueue_scripts`: the app's script and styles on the grid page only. */
    public static function enqueue(string $hook): void
    {
        if (self::eventId() === null || !str_contains($hook, 'registrations')) {
            return;
        }
        $built = dirname(KALAKA_FILE) . '/build/Admin/app/';
        if (!is_file($built . 'grid.asset.php')) {
            return;
        }
        /** @var array{dependencies: list<non-empty-string>, version: string} $asset */
        $asset = require $built . 'grid.asset.php';
        $src = plugins_url('build/Admin/app/grid.js', KALAKA_FILE);
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
        // DataViews' own stylesheet (the build names a bundled `style.css` `style-grid.css`), then the page's.
        wp_register_style(
            self::HANDLE . '-dataviews',
            plugins_url('build/Admin/app/style-grid.css', KALAKA_FILE),
            ['wp-components'],
            $asset['version']
        );
        wp_enqueue_style(
            self::HANDLE,
            plugins_url('build/Admin/app/grid.css', KALAKA_FILE),
            [self::HANDLE . '-dataviews'],
            $asset['version']
        );
        Translations::load(self::HANDLE);
    }

    /** The page: through the error boundary, the access checked again. */
    public static function render(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Boundary::render() returns escaped HTML
        echo Boundary::render('the registrants page', static fn (): string => self::html());
    }

    /**
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied when the user reaches nothing on it
     */
    private static function html(): string
    {
        $eventId = self::eventId() ?? throw new NotFound();
        $event = (new Events())->find($eventId) ?? throw new NotFound();
        $actor = new UserActor(get_current_user_id());
        $access = new Access();
        $full = $access->isFull($actor);

        $held = $full ? [$access->master($actor, $eventId)] : [];
        if ($full) {
            foreach ((new ViewList())->ofEvent($eventId) as $view) {
                $held[] = $access->fullView($actor, $eventId, $view['id']);
            }
        } else {
            $seen = [];
            foreach ($access->heldViews($actor, $eventId) as $view) {
                $key = $view->viewId ?? 0;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $held[] = $view;
                }
            }
            if ($held === [] && !in_array($eventId, $access->grantedEventIds($actor), true)) {
                throw new PermissionDenied();
            }
        }

        $head = '<div class="wrap kalaka-grid-wrap"><h1>' . esc_html($event->name) . '</h1>';
        if ($held === []) {
            return $head . '<p>' . esc_html(Texts::NO_VIEW) . '</p></div>';
        }

        $fields = (new FieldDefinitions())->of($eventId);
        $model = new GridModel();
        $tabs = array_map(static fn (HeldView $view): array => [
            'view' => $view->viewId ?? 0,
            'name' => $view->name ?? Texts::REGISTRANTS,
            'columns' => $model->columns($view, $fields),
        ], $held);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
        $asked = isset($_GET['view']) ? absint($_GET['view']) : null;
        $current = in_array($asked, array_column($tabs, 'view'), true) ? $asked : $tabs[0]['view'];

        $config = [
            'event' => $eventId,
            'full' => $full,
            'tabs' => $tabs,
            'current' => $current,
            'perPages' => self::PER_PAGE,
            'maxLimit' => ViewQuery::MAX_LIMIT,
            'sumEditable' => $full,
            'rest' => '/' . GridRoutes::NAMESPACE . '/events/' . $eventId,
        ];
        wp_add_inline_script(self::HANDLE, 'window.kalakaGrid = ' . wp_json_encode($config) . ';', 'before');
        wp_enqueue_script(self::HANDLE);

        return $head . '<div id="kalaka-grid"></div></div>';
    }

    private static function eventId(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return preg_match('/^kalaka-event-([1-9][0-9]*)-registrations$/', $page, $m) === 1 ? (int) $m[1] : null;
    }

    /** @internal For tests: the slug of an event's page. */
    public static function slug(Event|int $event): string
    {
        return sprintf(self::SLUG, $event instanceof Event ? $event->id : $event);
    }
}
