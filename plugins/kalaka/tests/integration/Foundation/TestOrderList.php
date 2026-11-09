<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Query\Query;

/** A test query class over a main table and a related one. */
final class TestOrderList extends Query
{
    public static function create(): void
    {
        global $wpdb;
        $wpdb->query(
            "CREATE TABLE {$wpdb->prefix}kalaka_test_orders "
            . "(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL) ENGINE=InnoDB"
        );
        $wpdb->query(
            "CREATE TABLE {$wpdb->prefix}kalaka_test_products "
            . "(id INT UNSIGNED PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB"
        );
    }

    /** @return list<array<string, ?string>> the orders, each with the name of its product */
    public function orders(): array
    {
        return $this->scope(function (): array {
            $orders = $this->select('order', 'SELECT * FROM %i ORDER BY id', [$this->table('kalaka_test_orders')]);
            $products = $this->related(
                'product',
                'kalaka_test_products',
                'id',
                array_map(static fn (array $order): string => (string) $order['product_id'], $orders)
            );
            $names = array_column($products, 'name', 'id');

            return array_map(
                static fn (array $order): array => $order + ['name' => $names[$order['product_id']] ?? null],
                $orders
            );
        });
    }

    /** One related load per order: the N+1 the base refuses. */
    public function ordersWithOneLoadEach(): void
    {
        $this->scope(function (): void {
            foreach ($this->select('order', 'SELECT * FROM %i', [$this->table('kalaka_test_orders')]) as $order) {
                $this->related('product', 'kalaka_test_products', 'id', [(int) $order['product_id']]);
            }
        });
    }

    /** @return list<array<string, ?string>> */
    public function relatedOf(array $keys): array
    {
        return $this->scope(fn (): array => $this->related('product', 'kalaka_test_products', 'id', $keys));
    }

    public function outsideAScope(): void
    {
        $this->select('order', 'SELECT 1');
    }

    public function scopeInsideAScope(): void
    {
        $this->scope(fn () => $this->scope(static function (): void {
        }));
    }

    public function relatedOfAnEvent(int $eventId): void
    {
        $this->scope(fn () => $this->related('product', 'kalaka_test_products', 'id', [1, 2], $eventId));
    }
}
