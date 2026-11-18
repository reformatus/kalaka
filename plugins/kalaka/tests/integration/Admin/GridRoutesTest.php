<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\SaveView;
use Kalaka\Admin\View\ViewList;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * S37: the grid's routes under `kalaka-admin/v1`. The gate lets in full access and grant holders only; a
 * grant holder reads only their own views, never the master view or another view. The routes only read:
 * the app's writes run the abilities through core's run endpoint, as `app/grid/api.js` calls them, and a
 * grant holder saves nothing there.
 */
final class GridRoutesTest extends GrantTestCase
{
    private int $event;

    private int $view;

    private int $other;

    private int $holder;

    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->createEvent();
        foreach (['00A0' => 'a', '00A2' => 'b', '00A5' => 'a'] as $code => $module) {
            $this->addRegistration($this->event, $code);
            $this->addValue($this->event, $code, 'module', $module);
        }
        $this->addRegistration($this->event, '00A8', 'waitlisted');
        $this->view = $this->parameterView($this->event);
        $this->other = $this->plainView($this->event);
        $this->holder = self::factory()->user->create(['role' => 'kalaka_grantee']);
        $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $this->holder,
            'view_id' => $this->view,
            'bound_value' => 'a',
            'actions' => [],
        ]);
        // The routes run the production runner; it joins the harness's open transaction here.
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    /** @param array<string, mixed> $params */
    private function rest(string $method, string $route, array $params = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/kalaka-admin/v1/events/' . $this->event . $route);
        if ($method === 'GET' || $method === 'DELETE') {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    /**
     * An ability over core's run endpoint, as the app calls it: `POST`, the input under `input`.
     *
     * @param array<string, mixed> $input
     */
    private function ability(string $name, array $input): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/wp-abilities/v1/abilities/' . $name . '/run');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(['input' => $input]));
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    private function as(int $user): void
    {
        wp_set_current_user($user);
        Access::forget();
    }

    public function testFullAccessReadsTheMasterViewAndEveryViewWithCoresPaging(): void
    {
        $this->as($this->admin->userId);

        $master = $this->rest('GET', '/views/0/rows', ['per_page' => 3]);
        self::assertSame(200, $master->get_status());
        self::assertCount(3, $master->get_data());
        self::assertSame('4', $master->get_headers()['X-WP-Total']);
        self::assertSame('2', $master->get_headers()['X-WP-TotalPages']);

        // Full access opens a view with a parameter without a bound value: the whole view.
        $view = $this->rest('GET', '/views/' . $this->view . '/rows');
        self::assertSame(200, $view->get_status());
        self::assertSame('4', $view->get_headers()['X-WP-Total']);
        self::assertSame(200, $this->rest('GET', '/views/' . $this->view . '/sums')->get_status());
        self::assertSame(200, $this->rest('GET', '/views')->get_status());
    }

    public function testAGrantHolderReadsOnlyTheirViewWithTheirBoundValue(): void
    {
        $this->as($this->holder);

        $rows = $this->rest('GET', '/views/' . $this->view . '/rows');
        self::assertSame(200, $rows->get_status());
        self::assertSame(['00A0', '00A5'], array_column($rows->get_data(), 'key'));
        $sums = $this->rest('GET', '/views/' . $this->view . '/sums')->get_data();
        self::assertSame(2, ((array) $sums)['code']['n']);

        foreach (['/views/0/rows', '/views/0/sums', '/views/' . $this->other . '/rows'] as $route) {
            self::assertSame(403, $this->rest('GET', $route)->get_status(), $route);
        }
        $views = $this->rest('GET', '/views')->get_data();
        self::assertIsArray($views);
        self::assertFalse($views['full']);
        self::assertSame([$this->view], array_column($views['views'], 'view'));
    }

    public function testAGrantHolderSavesNothing(): void
    {
        $this->as($this->holder);
        $stored = (new ViewList())->ofEvent($this->event)[0];

        self::assertSame(403, $this->ability('kalaka/save-view', [
            'id' => $stored['id'],
            'version' => $stored['version'],
            'event_id' => $this->event,
            'name' => $stored['name'],
            'filter' => json_decode($stored['filter'], true),
            'columns' => json_decode($stored['columns'], true),
            'counts' => ['status' => ['waitlisted']],
        ])->get_status());
        self::assertSame(403, $this->ability('kalaka/save-master-counts', [
            'event_id' => $this->event,
            'column' => 'status',
            'members' => ['waitlisted'],
        ])->get_status());
        self::assertSame(403, $this->ability('kalaka/delete-view', [
            'id' => $stored['id'],
            'version' => $stored['version'],
        ])->get_status());
        self::assertSame(403, $this->ability('kalaka/save-view', [
            'event_id' => $this->event,
            'name' => 'X',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false]],
        ])->get_status());
        self::assertCount(2, (new ViewList())->ofEvent($this->event));
        self::assertSame([], (new ViewList())->masterCounts($this->event));
    }

    public function testTheViewsRoutesOnlyRead(): void
    {
        $this->as($this->admin->userId);

        $writes = ['POST' => '/views', 'PUT' => '/views/' . $this->view, 'DELETE' => '/views/' . $this->view];
        foreach ($writes as $method => $route) {
            self::assertSame(404, $this->rest($method, $route)->get_status(), $method . ' ' . $route);
        }
        self::assertSame(404, $this->rest('PUT', '/views/0/counts', ['members' => []])->get_status());
        self::assertSame(404, $this->rest('POST', '/grants')->get_status());
    }

    public function testAUserWithoutAGrantOrAGuestIsStoppedAtTheGate(): void
    {
        foreach ([self::factory()->user->create(['role' => 'editor']), 0] as $user) {
            $this->as($user);
            $status = $this->rest('GET', '/views/' . $this->view . '/rows')->get_status();
            self::assertContains($status, [401, 403], (string) $user);
        }
    }

    public function testFullAccessSavesTheCountsOfAViewAndOfTheMasterViewThroughTheAbilities(): void
    {
        $listed = $this->exec(new SaveView(), [
            'event_id' => $this->event,
            'name' => 'Állapotok',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]],
        ]);
        self::assertIsInt($listed);
        $this->as($this->admin->userId);
        $input = [
            'id' => $listed,
            'version' => 1,
            'event_id' => $this->event,
            'name' => 'Állapotok',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]],
        ];

        $saved = $this->ability('kalaka/save-view', $input + ['counts' => ['status' => ['waitlisted']]]);
        self::assertSame(200, $saved->get_status());
        self::assertSame('{"status":["waitlisted"]}', $this->viewRow($listed)['counts']);
        $sums = (array) $this->rest('GET', '/views/' . $listed . '/sums')->get_data();
        self::assertSame(1, $sums['status']['n']);
        // A stale version is refused with the conflict the dialog shows.
        $stale = $this->ability('kalaka/save-view', $input + ['counts' => ['status' => ['registered']]]);
        self::assertSame(409, $stale->get_status());
        self::assertSame('kalaka_version_conflict', ((array) $stale->get_data())['code']);

        $master = $this->ability('kalaka/save-master-counts', [
            'event_id' => $this->event,
            'column' => 'status',
            'members' => ['waitlisted'],
        ]);
        self::assertSame(200, $master->get_status());
        self::assertSame(['status' => ['waitlisted']], (new ViewList())->masterCounts($this->event));
        self::assertSame(1, ((array) $this->rest('GET', '/views/0/sums')->get_data())['status']['n']);
    }

    public function testAnUnknownEventOrViewIsNotFound(): void
    {
        $this->as($this->admin->userId);

        self::assertSame(404, $this->rest('GET', '/views/999999/rows')->get_status());
        $request = new \WP_REST_Request('GET', '/kalaka-admin/v1/events/999999/views/0/rows');
        self::assertSame(404, rest_do_request($request)->get_status());
    }
}
