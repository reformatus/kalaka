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
 * grant holder reads only their own views, never the master view or another view, and saves nothing.
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
        $version = (new ViewList())->ofEvent($this->event)[0]['version'];

        self::assertSame(403, $this->rest('PUT', '/views/' . $this->view . '/counts', [
            'column' => 'status',
            'members' => ['waitlisted'],
        ])->get_status());
        self::assertSame(403, $this->rest('PUT', '/views/0/counts', [
            'column' => 'status',
            'members' => ['waitlisted'],
        ])->get_status());
        self::assertSame(403, $this->rest('DELETE', '/views/' . $this->view, ['version' => $version])->get_status());
        self::assertSame(403, $this->rest('POST', '/views', [
            'name' => 'X',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false]],
        ])->get_status());
        self::assertCount(2, (new ViewList())->ofEvent($this->event));
    }

    public function testAUserWithoutAGrantOrAGuestIsStoppedAtTheGate(): void
    {
        foreach ([self::factory()->user->create(['role' => 'editor']), 0] as $user) {
            $this->as($user);
            $status = $this->rest('GET', '/views/' . $this->view . '/rows')->get_status();
            self::assertContains($status, [401, 403], (string) $user);
        }
    }

    public function testFullAccessSavesTheCountsOfAViewAndOfTheMasterView(): void
    {
        $listed = $this->exec(new SaveView(), [
            'event_id' => $this->event,
            'name' => 'Állapotok',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false], ['column' => 'status', 'write' => false]],
        ]);
        self::assertIsInt($listed);
        $this->as($this->admin->userId);

        $view = $this->rest('PUT', '/views/' . $listed . '/counts', [
            'column' => 'status',
            'members' => ['waitlisted'],
        ]);
        self::assertSame(200, $view->get_status());
        self::assertSame('{"status":["waitlisted"]}', $this->viewRow($listed)['counts']);
        // A column the view does not list cannot carry counts.
        self::assertSame(400, $this->rest('PUT', '/views/' . $this->other . '/counts', [
            'column' => 'status',
            'members' => ['waitlisted'],
        ])->get_status());

        $master = $this->rest('PUT', '/views/0/counts', ['column' => 'status', 'members' => ['waitlisted']]);
        self::assertSame(200, $master->get_status());
        self::assertSame(1, ((array) $master->get_data())['status']['n']);
        self::assertSame(['status' => ['waitlisted']], (new ViewList())->masterCounts($this->event));
    }

    public function testAnUnknownEventOrViewIsNotFound(): void
    {
        $this->as($this->admin->userId);

        self::assertSame(404, $this->rest('GET', '/views/999999/rows')->get_status());
        $request = new \WP_REST_Request('GET', '/kalaka-admin/v1/events/999999/views/0/rows');
        self::assertSame(404, rest_do_request($request)->get_status());
    }
}
