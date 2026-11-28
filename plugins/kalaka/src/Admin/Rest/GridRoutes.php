<?php

declare(strict_types=1);

namespace Kalaka\Admin\Rest;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Grid\FieldDefinitions;
use Kalaka\Admin\Grid\GridModel;
use Kalaka\Admin\Grid\GridNarrowing;
use Kalaka\Admin\Grid\ViewsData;
use Kalaka\Admin\View\ViewList;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * The grid's REST routes under `kalaka-admin/v1` (S37), each through `Boundary::route()`: the table data the
 * app reads. The gate of every route is `Access::mayReachAdmin()`; reads go through a view `Access` hands out
 * (`{view}` 0 is the master view, which is full access only). The grid app writes nothing of a view
 * (the counting is the viewer's own, for the session); the abilities `kalaka/save-view` and `kalaka/delete-view` stay.
 *
 * - `GET /events/{id}/views/{view}/rows?grant&page&per_page&orderby&order&filters&search`: one page,
 *   following core's paging (`X-WP-Total`, `X-WP-TotalPages`); `orderby` is a listed column; `code`
 *   is the default order (the ordering time, then the code), ascending or descending, and `serial` is a number
 *   sort that falls back to it.
 *   `filters` (a JSON list of `{field, operator, value}`) and `search` narrow the view's own rows, on listed
 *   columns only (`GridNarrowing`); anything else is a 400.
 * - `GET /events/{id}/views/{view}/sums?grant&counts&filters&search`: the sum row over the whole view, or
 *   over the rows the filters and the search leave; the aggregates are built here from the view's columns,
 *   the field definitions and the stored counts. Any holder of the view may send an optional `counts`
 *   override (never stored, validated against the held view's listed columns and their members), so the
 *   viewer's own counting changes only this request's sum row.
 * - `GET /events/{id}/views`: the views list (`ViewsData`; the grid app no longer reads it); for full access
 *   each view also carries its `version`, which a save sends back.
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
        // A grant holder names the grant whose view they open: one tab per grant, even on the same view.
        $grant = ['type' => 'integer', 'minimum' => 0, 'default' => 0];
        $gate = [self::class, 'permission'];
        // The filters are a JSON list, checked by `GridNarrowing`; the caps are `Narrowing`'s.
        $filters = ['type' => 'string', 'maxLength' => 8192];
        $search = ['type' => 'string', 'maxLength' => Narrowing::MAX_SEARCH];

        Boundary::route(self::NAMESPACE, self::VIEW . '/rows', [
            'methods' => 'GET',
            'callback' => [self::class, 'rows'],
            'permission_callback' => $gate,
            'args' => [
                'id' => $id,
                'view' => $view,
                'grant' => $grant,
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ViewQuery::MAX_LIMIT, 'default' => 50],
                'orderby' => ['type' => 'string'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                'filters' => $filters,
                'search' => $search,
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::VIEW . '/sums', [
            'methods' => 'GET',
            'callback' => [self::class, 'sums'],
            'permission_callback' => $gate,
            'args' => [
                'id' => $id,
                'view' => $view,
                'grant' => $grant,
                // A grant holder's own counting, never stored on the shared view (topic 6a G1).
                'counts' => ['type' => 'string'],
                'filters' => $filters,
                'search' => $search,
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::EVENT . '/views', [
            'methods' => 'GET',
            'callback' => [self::class, 'views'],
            'permission_callback' => $gate,
            'args' => ['id' => $id],
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
        $model = new GridModel();
        $fields = (new FieldDefinitions())->of($view->eventId);
        $numeric = $model->numericColumns($view, $fields);
        $page = (new ViewQuery())->page(
            $view,
            $sort,
            $request->get_param('order') === 'desc',
            $perPage,
            ((int) $request->get_param('page') - 1) * $perPage,
            $numeric,
            self::narrowing($request, $view, $fields)
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

        $narrowing = self::narrowing($request, $view, $fields);

        return new \WP_REST_Response((object) (new GridModel())->sums(
            $view,
            $fields,
            $narrowing,
            self::countsParam($request)
        ));
    }

    /**
     * The ad hoc counted members a grant holder sends with the sums request (G1): an object of column →
     * member list. Never stored; `GridModel` validates it against the view's columns and members.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return array<string, list<string>>|null
     * @throws InvalidInput when the JSON is not an object of lists
     */
    private static function countsParam(\WP_REST_Request $request): ?array
    {
        $raw = $request->get_param('counts');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > 8192) {
            throw new InvalidInput();
        }
        $decoded = json_decode($raw, true);
        // An empty object is no override: the server's stored choice (with its default) applies. `{}` decodes
        // to `[]`, which `array_is_list()` would otherwise call a list.
        if ($decoded === []) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidInput();
        }
        $counts = [];
        foreach ($decoded as $column => $members) {
            if (!is_string($column) || !is_array($members) || !array_is_list($members)) {
                throw new InvalidInput();
            }
            foreach ($members as $member) {
                if (!is_string($member)) {
                    throw new InvalidInput();
                }
            }
            $counts[$column] = $members;
        }

        return $counts;
    }

    /**
     * The request's filters and search for the view.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @param array<string, \Kalaka\Admin\Grid\FieldDefinition> $fields
     * @throws InvalidInput
     */
    private static function narrowing(\WP_REST_Request $request, HeldView $view, array $fields): Narrowing
    {
        return (new GridNarrowing())->of($view, $fields, $request->get_param('filters'), $request->get_param('search'));
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
     * The view the current user opens: the master view (0) or a stored one through full access, otherwise
     * the view of the grant the request names (`grant`), which must be on that view.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied when the user holds no such view
     */
    private static function held(\WP_REST_Request $request): HeldView
    {
        return (new Access())->viewOf(
            self::actor(),
            self::event($request),
            (int) $request->get_param('view'),
            (int) $request->get_param('grant')
        );
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

    private static function actor(): UserActor
    {
        return new UserActor(get_current_user_id());
    }
}
