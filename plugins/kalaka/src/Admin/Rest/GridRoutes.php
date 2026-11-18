<?php

declare(strict_types=1);

namespace Kalaka\Admin\Rest;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\Grid\FieldDefinitions;
use Kalaka\Admin\Grid\GridModel;
use Kalaka\Admin\Grid\ViewsData;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveMasterCounts;
use Kalaka\Admin\View\SaveView;
use Kalaka\Admin\View\ViewList;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The grid's REST routes under `kalaka-admin/v1` (S37), each through `Boundary::route()`. The gate of every
 * route is `Access::mayReachAdmin()`; reads go through a view `Access` hands out (`{view}` 0 is the master
 * view, which is full access only), writes through the runner, which decides each call.
 *
 * - `GET /events/{id}/views/{view}/rows?page&per_page&orderby&order`: one page, following core's paging
 *   (`X-WP-Total`, `X-WP-TotalPages`); `orderby` is a listed column other than `code` and `serial`.
 * - `GET /events/{id}/views/{view}/sums`: the sum row over the whole view; the aggregates are built here
 *   from the view's columns, the field definitions and the stored counts, never taken from the client.
 * - `PUT /events/{id}/views/{view}/counts` `{column, members}`: the members one column's sum counts
 *   (full access), through `kalaka/save-view` or, for the master view, `kalaka/save-master-counts`;
 *   answers the sum row.
 * - `GET /events/{id}/views`: the „Nézetek” dialog's list (`ViewsData`).
 * - `POST /events/{id}/views`, `PUT|DELETE /events/{id}/views/{view}`: `kalaka/save-view`, `kalaka/delete-view`.
 * - `POST /events/{id}/grants`, `PUT|DELETE /events/{id}/grants/{grant}`: `kalaka/save-grant`,
 *   `kalaka/delete-grant`.
 */
final class GridRoutes
{
    public const NAMESPACE = 'kalaka-admin/v1';

    private const EVENT = '/events/(?P<id>[1-9][0-9]*)';
    private const VIEW = self::EVENT . '/views/(?P<view>[0-9]+)';

    /** `rest_api_init` */
    public static function register(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'required' => true];
        $view = ['type' => 'integer', 'minimum' => 0, 'required' => true];
        $gate = [self::class, 'permission'];

        Boundary::route(self::NAMESPACE, self::VIEW . '/rows', [
            'methods' => 'GET',
            'callback' => [self::class, 'rows'],
            'permission_callback' => $gate,
            'args' => [
                'id' => $id,
                'view' => $view,
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ViewQuery::MAX_LIMIT, 'default' => 50],
                'orderby' => ['type' => 'string'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::VIEW . '/sums', [
            'methods' => 'GET',
            'callback' => [self::class, 'sums'],
            'permission_callback' => $gate,
            'args' => ['id' => $id, 'view' => $view],
        ]);
        Boundary::route(self::NAMESPACE, self::VIEW . '/counts', [
            'methods' => 'PUT',
            'callback' => [self::class, 'counts'],
            'permission_callback' => $gate,
            'args' => [
                'id' => $id,
                'view' => $view,
                'column' => ['type' => 'string', 'required' => true],
                'members' => ['type' => 'array', 'items' => ['type' => 'string'], 'required' => true],
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/views', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'views'],
                'permission_callback' => $gate,
                'args' => ['id' => $id],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'saveView'],
                'permission_callback' => $gate,
                'args' => ['id' => $id],
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::VIEW, [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'saveView'],
                'permission_callback' => $gate,
                'args' => ['id' => $id, 'view' => $view],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'deleteView'],
                'permission_callback' => $gate,
                'args' => [
                    'id' => $id,
                    'view' => $view,
                    'version' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                ],
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/grants', [
            'methods' => 'POST',
            'callback' => [self::class, 'saveGrant'],
            'permission_callback' => $gate,
            'args' => ['id' => $id],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/grants/(?P<grant>[1-9][0-9]*)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'saveGrant'],
                'permission_callback' => $gate,
                'args' => ['id' => $id, 'grant' => $id],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'deleteGrant'],
                'permission_callback' => $gate,
                'args' => ['id' => $id, 'grant' => $id],
            ],
        ]);
    }

    /**
     * The gate: full access or at least one grant. The runner and `Access` decide each call.
     *
     * @throws PermissionDenied
     */
    public static function permission(): bool
    {
        if (!Access::mayReachAdmin(get_current_user_id())) {
            throw new PermissionDenied();
        }

        return true;
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public static function rows(\WP_REST_Request $request): \WP_REST_Response
    {
        $view = self::held($request);
        $perPage = (int) $request->get_param('per_page');
        $orderby = $request->get_param('orderby');
        $sort = is_string($orderby) && $orderby !== '' ? $orderby : null;
        if ($sort === 'serial') {
            throw new InvalidInput();
        }
        $page = (new ViewQuery())->page(
            $view,
            $sort,
            $request->get_param('order') === 'desc',
            $perPage,
            ((int) $request->get_param('page') - 1) * $perPage
        );

        $rows = [];
        foreach ($page->rows as $row) {
            $rows[] = ['key' => $row->key->code, 'values' => (object) $row->values];
        }
        $response = new \WP_REST_Response($rows);
        $response->header('X-WP-Total', (string) $page->total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($page->total / $perPage)));

        return $response;
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public static function sums(\WP_REST_Request $request): \WP_REST_Response
    {
        $view = self::held($request);
        $fields = (new FieldDefinitions())->of($view->eventId);

        return new \WP_REST_Response((object) (new GridModel())->sums($view, $fields));
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for a view that is not the event's
     */
    public static function counts(\WP_REST_Request $request): \WP_REST_Response
    {
        $view = self::held($request);
        $column = (string) $request->get_param('column');
        $members = array_values(array_map('strval', (array) $request->get_param('members')));

        if ($view->isMaster()) {
            $input = ['event_id' => $view->eventId, 'column' => $column, 'members' => $members];
            self::run(SaveMasterCounts::prepared($input), $input);
        } else {
            $stored = self::storedView($view->eventId, (int) $view->viewId);
            $counts = json_decode((string) ($stored['counts'] ?? ''), true);
            $counts = is_array($counts) ? $counts : [];
            $counts[$column] = $members;
            if ($members === []) {
                unset($counts[$column]);
            }
            $input = [
                'id' => $stored['id'],
                'version' => $stored['version'],
                'event_id' => $view->eventId,
                'name' => $stored['name'],
                'filter' => json_decode($stored['filter'], true),
                'columns' => json_decode($stored['columns'], true),
                'counts' => $counts,
            ];
            self::run(SaveView::prepared($input), $input);
        }

        $held = self::held($request);
        $fields = (new FieldDefinitions())->of($held->eventId);

        return new \WP_REST_Response((object) (new GridModel())->sums($held, $fields));
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public static function views(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = self::event($request);
        $fields = (new FieldDefinitions())->of($eventId);
        $data = (new ViewsData())->of(self::actor(), $eventId, $fields);
        if ($data['full']) {
            $versions = array_column((new ViewList())->ofEvent($eventId), 'version', 'id');
            foreach ($data['views'] as $index => $view) {
                $data['views'][$index]['version'] = $versions[$view['view']] ?? null;
            }
        }

        return new \WP_REST_Response($data);
    }

    /**
     * `{name, filter, columns, counts?}` and, to replace a stored view, its `version`.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function saveView(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = self::event($request);
        $input = ['event_id' => $eventId];
        foreach (['name', 'filter', 'columns', 'counts', 'version'] as $name) {
            if ($request->has_param($name) && $request->get_param($name) !== null) {
                $input[$name] = $request->get_param($name);
            }
        }
        if ($request->has_param('view')) {
            $input['id'] = (int) $request->get_param('view');
        }

        return new \WP_REST_Response(['id' => self::run(SaveView::prepared($input), $input)]);
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public static function deleteView(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = self::event($request);
        $id = (int) $request->get_param('view');
        self::storedView($eventId, $id);
        self::run(new DeleteView(), ['id' => $id, 'version' => (int) $request->get_param('version')]);

        return new \WP_REST_Response(null, 204);
    }

    /**
     * `{user_id, view_id?, bound_value?, actions}` and, to replace a stored grant, its `version`.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function saveGrant(\WP_REST_Request $request): \WP_REST_Response
    {
        $input = ['event_id' => self::event($request)];
        foreach (['user_id', 'view_id', 'bound_value', 'actions', 'version'] as $name) {
            if ($request->has_param($name)) {
                $input[$name] = $request->get_param($name);
            }
        }
        if ($request->has_param('grant')) {
            $input['id'] = (int) $request->get_param('grant');
        }

        return new \WP_REST_Response(['id' => self::run(new SaveGrant(), $input)]);
    }

    /** @param \WP_REST_Request<array<string, mixed>> $request */
    public static function deleteGrant(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = self::event($request);
        $id = (int) $request->get_param('grant');
        if (!in_array($id, array_column((new GrantQuery())->ofEvent($eventId), 'id'), true)) {
            throw new NotFound();
        }
        self::run(new DeleteGrant(), ['id' => $id]);

        return new \WP_REST_Response(null, 204);
    }

    /**
     * The view the current user opens: the master view (0) or a stored one through full access, otherwise
     * the first of their held views on that view.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied when the user holds no such view
     */
    private static function held(\WP_REST_Request $request): HeldView
    {
        $eventId = self::event($request);
        $viewId = (int) $request->get_param('view');
        $actor = self::actor();
        $access = new Access();
        if ($access->isFull($actor)) {
            return $viewId === 0 ? $access->master($actor, $eventId) : $access->fullView($actor, $eventId, $viewId);
        }
        foreach ($access->heldViews($actor, $eventId) as $held) {
            if (($held->viewId ?? 0) === $viewId) {
                return $held;
            }
        }

        throw new PermissionDenied();
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound
     */
    private static function event(\WP_REST_Request $request): int
    {
        $eventId = (int) $request->get_param('id');
        if ((new Events())->find($eventId) === null) {
            throw new NotFound();
        }

        return $eventId;
    }

    /**
     * @return array{id: int, version: int, name: string, filter: string, columns: string, counts: ?string}
     * @throws NotFound
     */
    private static function storedView(int $eventId, int $viewId): array
    {
        foreach ((new ViewList())->ofEvent($eventId) as $view) {
            if ($view['id'] === $viewId) {
                return $view;
            }
        }

        throw new NotFound();
    }

    private static function actor(): UserActor
    {
        return new UserActor(get_current_user_id());
    }

    /** @param array<string, mixed> $input */
    private static function run(AdminUseCase $useCase, array $input): mixed
    {
        return Runner::current()->run($useCase, $input, self::actor());
    }
}
