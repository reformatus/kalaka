<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use WP_UnitTestCase;

/** The query base: one statement per record type per list, and a statement only inside a scope. */
final class QueryTest extends WP_UnitTestCase
{
    private TestOrderList $list;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        TestOrderList::create();
        $this->list = new TestOrderList();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_orders");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_products");

        parent::tear_down();
    }

    private function seed(int $orders, int $from = 1): void
    {
        global $wpdb;
        for ($i = $from; $i < $from + $orders; $i++) {
            $wpdb->insert($wpdb->prefix . 'kalaka_test_products', ['id' => $i, 'name' => 'product ' . $i]);
            $wpdb->insert($wpdb->prefix . 'kalaka_test_orders', ['product_id' => $i]);
        }
    }

    public function testAListRunsTheSameTwoStatementsOverOneRowAndOverFifty(): void
    {
        global $wpdb;

        $this->seed(1);
        $before = $wpdb->num_queries;
        $one = $this->list->orders();
        $statementsForOne = $wpdb->num_queries - $before;

        $this->seed(50, 2);
        $before = $wpdb->num_queries;
        $many = $this->list->orders();
        $statementsForMany = $wpdb->num_queries - $before;

        self::assertCount(1, $one);
        self::assertCount(51, $many);
        self::assertSame(2, $statementsForOne);
        self::assertSame(2, $statementsForMany);
        self::assertSame('product 1', $one[0]['name']);
    }

    public function testALoadOfTheRelatedTypeOncePerRowIsRefusedAtTheSecondLoad(): void
    {
        $this->seed(3);

        $this->expectException(\LogicException::class);
        $this->list->ordersWithOneLoadEach();
    }

    public function testAnEmptyKeyListRunsNoStatement(): void
    {
        global $wpdb;

        $before = $wpdb->num_queries;
        $rows = $this->list->relatedOf([]);

        self::assertSame([], $rows);
        self::assertSame($before, $wpdb->num_queries);
    }

    public function testAStatementOutsideAScopeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->list->outsideAScope();
    }

    public function testAScopeInsideAScopeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->list->scopeInsideAScope();
    }

    public function testAScopeEndsEvenWhenItsWorkThrows(): void
    {
        $this->seed(2);

        try {
            $this->list->ordersWithOneLoadEach();
            self::fail('Two orders with one load each should have thrown.');
        } catch (\LogicException) {
            // Expected: the scope must end all the same.
        }

        self::assertCount(2, $this->list->orders());
    }

    public function testTheRelatedLoadOfAPerEventTableCarriesTheEventCondition(): void
    {
        $statements = [];
        $capture = static function (string $query) use (&$statements): string {
            $statements[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        $suppressed = $GLOBALS['wpdb']->suppress_errors(true);
        try {
            $this->list->relatedOfAnEvent(7);
        } catch (\Throwable) {
            // The test table has no event column; only the statement text matters.
        } finally {
            $GLOBALS['wpdb']->suppress_errors($suppressed);
            remove_filter('query', $capture);
        }

        self::assertCount(1, $statements);
        self::assertStringContainsString("WHERE event_id = 7 AND `id` IN (1, 2)", $statements[0]);
    }
}
