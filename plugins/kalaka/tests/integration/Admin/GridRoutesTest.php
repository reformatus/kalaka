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

    private int $grant;

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
        $grant = $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $this->holder,
            'view_id' => $this->view,
            'bound_value' => 'a',
            'actions' => [],
        ]);
        self::assertIsInt($grant);
        $this->grant = $grant;
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

    /** Topic 6a: Kód carries the default order both ways; Szám is not sortable yet. */
    public function testTheRowsRouteSortsByCodeBothWaysAndRefusesTheSerial(): void
    {
        $this->as($this->admin->userId);

        $keys = fn (array $params): array => array_column(
            (array) $this->rest('GET', '/views/0/rows', $params)->get_data(),
            'key'
        );
        $up = $keys(['orderby' => 'code', 'order' => 'asc']);
        $down = $keys(['orderby' => 'code', 'order' => 'desc']);
        $default = $keys([]);

        self::assertSame($default, $up);
        self::assertSame(array_reverse($up), $down);
        self::assertSame(400, $this->rest('GET', '/views/0/rows', ['orderby' => 'serial'])->get_status());
    }

    /** Topic 6a B1: nobody's counting is stored; full access sends it with the sums read like a grant holder. */
    public function testFullAccessCountsOnlyForTheSessionOnTheMasterViewAndAStoredView(): void
    {
        $this->as($this->admin->userId);
        $stored = (new ViewList())->masterCounts($this->event);

        $default = (array) $this->rest('GET', '/views/0/sums')->get_data();
        self::assertSame(3, $default['status']['n']);
        $own = (array) $this->rest('GET', '/views/0/sums', [
            'counts' => (string) wp_json_encode(['status' => ['waitlisted']]),
        ])->get_data();
        self::assertSame(1, $own['status']['n']);
        self::assertSame(['waitlisted'], $own['status']['members']);
        // Nothing was stored, so the next read is the default again.
        self::assertSame([], (new ViewList())->masterCounts($this->event));
        self::assertSame($stored, (new ViewList())->masterCounts($this->event));
        self::assertSame(3, ((array) $this->rest('GET', '/views/0/sums')->get_data())['status']['n']);

        // A column the view does not list is refused for full access too.
        self::assertSame(400, $this->rest('GET', '/views/' . $this->view . '/sums', [
            'counts' => (string) wp_json_encode(['status' => ['registered']]),
        ])->get_status());
    }

    public function testAGrantHolderReadsOnlyTheirViewWithTheirBoundValue(): void
    {
        $this->as($this->holder);

        $rows = $this->rest('GET', '/views/' . $this->view . '/rows', ['grant' => $this->grant]);
        self::assertSame(200, $rows->get_status());
        self::assertSame(['00A0', '00A5'], array_column($rows->get_data(), 'key'));
        $sums = $this->rest('GET', '/views/' . $this->view . '/sums', ['grant' => $this->grant])->get_data();
        self::assertSame(2, ((array) $sums)['code']['n']);

        foreach (['/views/0/rows', '/views/0/sums', '/views/' . $this->other . '/rows'] as $route) {
            self::assertSame(403, $this->rest('GET', $route)->get_status(), $route);
        }
        $views = $this->rest('GET', '/views')->get_data();
        self::assertIsArray($views);
        self::assertFalse($views['full']);
        self::assertSame([$this->view], array_column($views['views'], 'view'));
    }

    public function testAGrantHoldersOwnCountingChangesOnlyTheirSums(): void
    {
        // A view that lists the status, so its sum row can count status members.
        $view = $this->exec(new SaveView(), [
            'event_id' => $this->event,
            'name' => 'Státuszos',
            'filter' => [['column' => 'field:module', 'parameter' => true]],
            'columns' => [['column' => 'status', 'write' => false]],
        ]);
        self::assertIsInt($view);
        $grant = $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $this->holder,
            'view_id' => $view,
            'bound_value' => 'a',
            'actions' => [],
        ]);
        self::assertIsInt($grant);
        $path = '/views/' . $view . '/sums';

        $this->as($this->holder);
        $stored = (array) $this->rest('GET', $path, ['grant' => $grant])->get_data();
        self::assertSame(2, $stored['status']['n']);

        // The holder counts the waitlisted rows instead; the shared view keeps its stored choice.
        $own = (array) $this->rest('GET', $path, [
            'grant' => $grant,
            'counts' => (string) wp_json_encode(['status' => ['waitlisted']]),
        ])->get_data();
        self::assertSame(0, $own['status']['n']);
        $again = (array) $this->rest('GET', $path, ['grant' => $grant])->get_data();
        self::assertSame(2, $again['status']['n']);

        // A column the view does not list, or a member that is not the column's, is refused.
        foreach ([['code' => ['x']], ['status' => ['gone']]] as $counts) {
            self::assertSame(400, $this->rest('GET', $path, [
                'grant' => $grant,
                'counts' => (string) wp_json_encode($counts),
            ])->get_status());
        }
    }

    public function testAGrantHoldersSumsAcceptAnEmptyOverrideAndRefuseAnUnlistedColumn(): void
    {
        $this->as($this->holder);
        // This view lists only the code, so its sum row has no Állapot entry to override.
        $path = '/views/' . $this->view . '/sums';

        $plain = $this->rest('GET', $path, ['grant' => $this->grant]);
        self::assertSame(200, $plain->get_status());
        self::assertArrayNotHasKey('status', (array) $plain->get_data());

        // An empty object is no override (a cleared pick): the stored choice, never a 400.
        $empty = $this->rest('GET', $path, ['grant' => $this->grant, 'counts' => '{}']);
        self::assertSame(200, $empty->get_status());
        self::assertEquals($plain->get_data(), $empty->get_data());

        // A view without Állapot refuses a status override; the client never sends one now.
        $refused = $this->rest('GET', $path, [
            'grant' => $this->grant,
            'counts' => (string) wp_json_encode(['status' => ['registered']]),
        ]);
        self::assertSame(400, $refused->get_status());
    }

    public function testAGrantHolderWithTwoGrantsOnOneViewGetsOneTabPerGrantWithItsOwnRowsAndSums(): void
    {
        $second = $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $this->holder,
            'view_id' => $this->view,
            'bound_value' => 'b',
            'actions' => [],
        ]);
        self::assertIsInt($second);
        $this->as($this->holder);

        $tabs = $this->rest('GET', '/views')->get_data();
        self::assertIsArray($tabs);
        self::assertSame(['g' . $this->grant, 'g' . $second], array_column($tabs['views'], 'key'));
        self::assertSame([$this->view, $this->view], array_column($tabs['views'], 'view'));
        [$first, $other] = array_column($tabs['views'], 'name');
        self::assertStringEndsWith("\u{00A0}– a", $first);
        self::assertStringEndsWith("\u{00A0}– b", $other);

        $path = '/views/' . $this->view;
        $rowsA = $this->rest('GET', $path . '/rows', ['grant' => $this->grant]);
        $rowsB = $this->rest('GET', $path . '/rows', ['grant' => $second]);
        self::assertSame(['00A0', '00A5'], array_column($rowsA->get_data(), 'key'));
        self::assertSame(['00A2'], array_column($rowsB->get_data(), 'key'));
        $sumsA = (array) $this->rest('GET', $path . '/sums', ['grant' => $this->grant])->get_data();
        $sumsB = (array) $this->rest('GET', $path . '/sums', ['grant' => $second])->get_data();
        self::assertSame(2, $sumsA['code']['n']);
        self::assertSame(1, $sumsB['code']['n']);
        // No grant named, or one of another view: nothing.
        self::assertSame(403, $this->rest('GET', $path . '/rows')->get_status());
        $wrongView = $this->rest('GET', '/views/' . $this->other . '/rows', ['grant' => $second]);
        self::assertSame(403, $wrongView->get_status());
    }

    public function testAGrantWhoseBoundValueIsBlankNamesTheTabByTheViewAlone(): void
    {
        $blank = $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $this->holder,
            'view_id' => $this->view,
            'bound_value' => "  ",
            'actions' => [],
        ]);
        self::assertIsInt($blank);
        $this->as($this->holder);

        $tabs = $this->rest('GET', '/views')->get_data();
        self::assertIsArray($tabs);
        $names = array_column(array_filter(
            $tabs['views'],
            static fn (array $tab): bool => $tab['key'] === 'g' . $blank
        ), 'name');
        self::assertSame(['Nézet'], $names);
    }

    /** @param list<array<string, mixed>> $filters */
    private function narrow(array $filters, string $search = ''): array
    {
        return ['grant' => $this->grant, 'filters' => (string) wp_json_encode($filters), 'search' => $search];
    }

    public function testAGrantHolderNarrowsOnlyWithinTheirViewOnListedColumns(): void
    {
        $this->as($this->holder);
        $route = '/views/' . $this->view;
        $contains = static fn (string $text): array => [
            ['field' => 'code', 'operator' => 'contains', 'value' => $text],
        ];
        $total = fn (array $params): string => $this->rest('GET', $route . '/rows', $params)
            ->get_headers()['X-WP-Total'];

        $rows = $this->rest('GET', $route . '/rows', self::narrow($contains('a5')));
        self::assertSame(200, $rows->get_status());
        self::assertSame(['00A5'], array_column($rows->get_data(), 'key'));
        self::assertSame('1', $rows->get_headers()['X-WP-Total']);
        // Their bound value stays in force: a filter or a search for another module's row finds nothing.
        self::assertSame('0', $total(self::narrow($contains('00a2'))));
        self::assertSame('0', $total(self::narrow([], '00a2')));
        self::assertSame('2', $total(self::narrow($contains('00'))));
        // The sum row follows the narrowed rows.
        $sums = (array) $this->rest('GET', $route . '/sums', self::narrow($contains('a5')))->get_data();
        self::assertSame(1, $sums['code']['n']);
        self::assertSame(2, ((array) $this->rest('GET', $route . '/sums', self::narrow([]))->get_data())['code']['n']);
        // A column the view does not list, a foreign operator and bad JSON are refused.
        foreach (
            [
                self::narrow([['field' => 'field:module', 'operator' => 'contains', 'value' => 'a']]),
                self::narrow([['field' => 'code', 'operator' => 'isAny', 'value' => ['a']]]),
                ['grant' => $this->grant, 'filters' => '[{'],
            ] as $params
        ) {
            self::assertSame(400, $this->rest('GET', $route . '/rows', $params)->get_status());
            self::assertSame(400, $this->rest('GET', $route . '/sums', $params)->get_status());
        }
        // Over the search's length the request is refused before the query.
        $long = ['grant' => $this->grant, 'search' => str_repeat('a', 101)];
        self::assertSame(400, $this->rest('GET', $route . '/rows', $long)->get_status());
    }

    public function testFullAccessNarrowsTheMasterViewByStatusAndSearch(): void
    {
        $this->as($this->admin->userId);

        $waitlisted = [['field' => 'status', 'operator' => 'isAny', 'value' => ['waitlisted']]];
        $rows = $this->rest('GET', '/views/0/rows', self::narrow($waitlisted));
        self::assertSame(['00A8'], array_column($rows->get_data(), 'key'));
        self::assertSame('1', $rows->get_headers()['X-WP-Total']);
        self::assertSame('1', $rows->get_headers()['X-WP-TotalPages']);
        // The status word, with a capital and an accent folded away.
        $keys = fn (array $params): array => array_column(
            $this->rest('GET', '/views/0/rows', $params)->get_data(),
            'key'
        );
        self::assertSame(['00A8'], $keys(self::narrow([], 'VAROLISTAS')));
        // A search by the value of a field the master view lists.
        self::assertSame(['00A2'], $keys(self::narrow([], 'b')));
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

        $deleted = $this->ability('kalaka/delete-view', ['id' => $listed, 'version' => 2]);
        self::assertSame(200, $deleted->get_status());
        self::assertSame(['id' => $listed], $deleted->get_data());
    }

    public function testAnUnknownEventOrViewIsNotFound(): void
    {
        $this->as($this->admin->userId);

        self::assertSame(404, $this->rest('GET', '/views/999999/rows')->get_status());
        $request = new \WP_REST_Request('GET', '/kalaka-admin/v1/events/999999/views/0/rows');
        self::assertSame(404, rest_do_request($request)->get_status());
    }
}
