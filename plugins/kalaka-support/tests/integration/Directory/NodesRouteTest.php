<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/SupportTestCase.php';

/** AC14 to AC19: the public route, as a logged-out visitor. */
final class NodesRouteTest extends SupportTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->ids = $this->buildTree();
        wp_set_current_user(0);
        // The first request loads options and warms WordPress's caches.
        $this->get(['search' => 'warmup']);
    }

    public function tear_down(): void
    {
        LoadState::record([]);
        parent::tear_down();
    }

    /** @param array<string, mixed> $params */
    private function get(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/kalaka-support/v1/nodes');
        foreach ($params as $name => $value) {
            $request->set_param($name, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string> the names the route lists
     */
    private function names(array $params): array
    {
        $response = $this->get($params);
        self::assertSame(200, $response->get_status());

        return array_column($response->get_data(), 'name');
    }

    public function testASearchIsAccentBlindAndAnItemHasExactlyTheDocumentedKeys(): void
    {
        $response = $this->get(['search' => 'obuda']);

        self::assertSame(200, $response->get_status());
        $items = $response->get_data();
        self::assertCount(1, $items);
        self::assertSame('Óbudai Teszt Gyülekezet', $items[0]['name']);
        self::assertSame(['id', 'name', 'type', 'parent', 'ancestors'], array_keys($items[0]));
        self::assertSame($this->ids['D1-A-1'], $items[0]['id']);
        self::assertSame('Gyülekezet', $items[0]['type']);
        self::assertSame($this->ids['D1-A'], $items[0]['parent']);
        self::assertSame(
            [
                ['id' => $this->ids['ROOT'], 'name' => 'Teszt Egyház', 'type' => 'Felekezet'],
                ['id' => $this->ids['D1'], 'name' => 'Északi Teszt Egyházkerület', 'type' => 'Egyházkerület'],
                ['id' => $this->ids['D1-A'], 'name' => 'Budai Teszt Egyházmegye', 'type' => 'Egyházmegye'],
            ],
            $items[0]['ancestors']
        );
        self::assertSame('1', (string) $response->get_headers()['X-WP-Total']);
        self::assertSame('1', (string) $response->get_headers()['X-WP-TotalPages']);
    }

    public function testARootsParentIsZeroAndItsAncestorsAreEmpty(): void
    {
        $items = $this->get(['search' => 'masik'])->get_data();

        self::assertSame('Másik Közösség', $items[0]['name']);
        self::assertSame(0, $items[0]['parent']);
        self::assertSame([], $items[0]['ancestors']);
    }

    public function testASearchMatchingOnlyAnAliasFindsTheNodeAndNeitherAliasesNorExternalIdsAreShown(): void
    {
        $items = $this->get(['search' => 'rokusi'])->get_data();

        self::assertSame(['Teszt Alsóvárosi Gyülekezet'], array_column($items, 'name'));
        self::assertStringNotContainsString('Rókusi', (string) wp_json_encode($items));
        self::assertStringNotContainsString('D2-A-1', (string) wp_json_encode($items));
    }

    public function testEveryWordMustOccurInTheNameOrTheAliases(): void
    {
        self::assertSame(
            ['Teszt Fasor Gyülekezet', 'Teszt Fasori Gyülekezet'],
            $this->names(['search' => 'teszt fasor'])
        );
        self::assertSame(['Teszt Fasor Gyülekezet', 'Teszt Fasori Gyülekezet'], $this->names(['search' => '  FASOR   teszt ']));
        // "kapolna" occurs only in an alias, "fasori" only in the name
        self::assertSame(['Teszt Fasori Gyülekezet'], $this->names(['search' => 'kapolna fasori']));
    }

    public function testResultsAreInHungarianOrder(): void
    {
        $names = $this->names(['search' => 'teszt', 'per_page' => 100]);

        self::assertLessThan(array_search('Öröm Teszt', $names, true), array_search('Ottó Teszt', $names, true));
        self::assertSame(array_unique($names), $names);
    }

    public function testPagingSetsTheTotalsAndPageTwoHoldsTheThirdAndFourthMatches(): void
    {
        $all = $this->get(['search' => 'gyulekezet', 'per_page' => 100]);
        $second = $this->get(['search' => 'gyulekezet', 'per_page' => 2, 'page' => 2]);

        $total = count($all->get_data());
        self::assertGreaterThan(4, $total);
        self::assertSame(array_slice($all->get_data(), 2, 2), $second->get_data());
        self::assertSame((string) $total, (string) $second->get_headers()['X-WP-Total']);
        self::assertSame((string) (int) ceil($total / 2), (string) $second->get_headers()['X-WP-TotalPages']);
        self::assertSame([], $this->get(['search' => 'gyulekezet', 'per_page' => 2, 'page' => 99])->get_data());
    }

    public function testADeactivatedCongregationIsAbsent(): void
    {
        $this->deactivate('D1-A-1', 'D1-A', 'Gyülekezet', 'Óbudai Teszt Gyülekezet');

        self::assertSame([], $this->names(['search' => 'obuda']));
    }

    public function testACongregationUnderADeactivatedDeaneryIsAbsentFromSearchAndChildListingUntilItIsActiveAgain(): void
    {
        $this->deactivate('D1-A', 'D1', 'Egyházmegye', 'Budai Teszt Egyházmegye');

        self::assertSame([], $this->names(['search' => 'obuda']));
        self::assertSame([], $this->names(['parent' => $this->ids['D1-A']]));
        self::assertNotContains('Budai Teszt Egyházmegye', $this->names(['parent' => $this->ids['D1']]));

        $this->save([
            'external_id' => 'D1-A',
            'parent_external_id' => 'D1',
            'type' => 'Egyházmegye',
            'name' => 'Budai Teszt Egyházmegye',
            'active' => true,
        ]);

        self::assertSame(['Óbudai Teszt Gyülekezet'], $this->names(['search' => 'obuda']));
        self::assertCount(3, $this->names(['parent' => $this->ids['D1-A']]));
    }

    public function testTheParentFilterListsRootsChildrenAndNothingForUnknownOrInactiveParents(): void
    {
        self::assertSame(['Másik Közösség', 'Teszt Egyház'], $this->names(['parent' => 0]));
        self::assertSame(
            ['Budai Teszt Egyházmegye', 'Pesti Teszt Egyházmegye'],
            $this->names(['parent' => $this->ids['D1']])
        );
        self::assertSame(
            ['Pesti Teszt Egyházmegye'],
            $this->names(['parent' => $this->ids['D1'], 'search' => 'pesti'])
        );
        self::assertSame([], $this->names(['parent' => 999999]));

        $this->deactivate('D1-B', 'D1', 'Egyházmegye', 'Pesti Teszt Egyházmegye');
        self::assertSame([], $this->names(['parent' => $this->ids['D1-B']]));
    }

    public function testOneItemAndTwentyItemsRunTheSameThreeStatements(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->save([
                'external_id' => 'EXTRA-' . $i,
                'parent_external_id' => 'D1-A',
                'type' => 'Gyülekezet',
                'name' => 'Extra Teszt ' . $i,
            ]);
        }
        $counts = [];
        foreach ([['search' => 'obuda'], ['search' => 'teszt', 'per_page' => 20]] as $params) {
            global $wpdb;
            $before = $wpdb->num_queries;
            $response = $this->get($params);
            $counts[] = $wpdb->num_queries - $before;
            self::assertSame(200, $response->get_status());
        }

        self::assertSame([3, 3], $counts);
        self::assertCount(20, $this->get(['search' => 'teszt', 'per_page' => 20])->get_data());
    }

    public function testAnEmptyPageRunsTwoStatements(): void
    {
        global $wpdb;
        $before = $wpdb->num_queries;

        $this->get(['search' => 'nincsilyen']);

        self::assertSame(2, $wpdb->num_queries - $before);
    }

    public function testASearchOfOnlyNoBreakSpacesWithAParentListsThatParentsChildren(): void
    {
        self::assertSame(
            ['Budai Teszt Egyházmegye', 'Pesti Teszt Egyházmegye'],
            $this->names(['parent' => $this->ids['D1'], 'search' => "\u{00A0}\u{00A0}"])
        );
    }

    public function testALargePageWhoseOffsetFitsIsAcceptedAndEmpty(): void
    {
        $page = intdiv(PHP_INT_MAX, 10) + 1;

        self::assertSame([], $this->names(['search' => 'teszt', 'page' => $page, 'per_page' => 10]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badRequests(): iterable
    {
        yield 'a one-character search' => [['search' => 'a']];
        yield 'a blank search' => [['search' => '   ']];
        yield 'a search of only no-break spaces' => [['search' => "\u{00A0}\u{00A0}"]];
        yield 'a 101-character search' => [['search' => str_repeat('a', 101)]];
        yield 'per_page 101' => [['search' => 'teszt', 'per_page' => 101]];
        yield 'per_page 0' => [['search' => 'teszt', 'per_page' => 0]];
        yield 'page 0' => [['search' => 'teszt', 'page' => 0]];
        yield 'a negative parent' => [['parent' => -1]];
        yield 'a text parent' => [['parent' => 'x']];
        yield 'an offset past the largest integer' => [
            ['search' => 'teszt', 'page' => 1000000000000000000, 'per_page' => 10],
        ];
        yield 'neither search nor parent' => [[]];
        yield 'only paging' => [['page' => 2]];
    }

    /**
     * @dataProvider badRequests
     * @param array<string, mixed> $params
     */
    public function testBadParametersAnswerWithInvalidInputsCodeAndText(array $params): void
    {
        $response = $this->get($params);

        self::assertSame(400, $response->get_status());
        self::assertSame('kalaka_invalid_input', $response->get_data()['code']);
        self::assertSame('Hibás vagy hiányzó adat.', $response->get_data()['message']);
    }

    public function testAClosedSiteAnswersWithSystemFailureAndRunsNoStatementOnTheDirectory(): void
    {
        LoadState::record([new LoadFailure('test', 'Closed for the test.')]);
        $response = null;

        $statements = $this->statementsDuring(function () use (&$response): void {
            $response = $this->get(['search' => 'teszt']);
        });

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(500, $response->get_status());
        self::assertSame('kalaka_system_failure', $response->get_data()['code']);
        self::assertSame('Váratlan hiba történt. Próbáld újra később.', $response->get_data()['message']);
        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'kalaka_support_nodes')
        )));
    }

    public function testTheRouteDeclaresItsSchemaAndArgumentsForDiscovery(): void
    {
        $server = rest_get_server();
        $endpoints = $server->get_routes('kalaka-support/v1')['/kalaka-support/v1/nodes'];

        $data = $server->get_data_for_route('/kalaka-support/v1/nodes', $endpoints, 'help');

        self::assertSame('array', $data['schema']['type']);
        self::assertSame(['search', 'parent', 'page', 'per_page'], array_keys($data['endpoints'][0]['args']));
    }

    private function deactivate(string $externalId, string $parent, string $type, string $name): void
    {
        $this->save([
            'external_id' => $externalId,
            'parent_external_id' => $parent,
            'type' => $type,
            'name' => $name,
            'active' => false,
        ]);
    }
}
