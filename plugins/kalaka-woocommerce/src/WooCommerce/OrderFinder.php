<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Payments\Api\RegistrationRef;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Finds this plugin's orders through `wc_get_orders()` and its meta query, never post meta or SQL, so both
 * of WooCommerce's order-storage modes answer the same.
 */
final class OrderFinder
{
    /**
     * The registration's orders, newest first.
     *
     * @param list<string> $statuses WooCommerce statuses without the `wc-` prefix; `any` for all but trash
     * @return list<\WC_Order>
     */
    public function of(RegistrationKey $key, array $statuses): array
    {
        $found = wc_get_orders([
            'limit' => -1,
            'status' => $statuses,
            'orderby' => 'ID',
            'order' => 'DESC',
            'type' => 'shop_order',
            // `meta_key` and `meta_value`: the one meta filter both storage modes support (`meta_query` is HPOS-only).
            'meta_key' => OrderMeta::REGISTRATION,
            'meta_value' => RegistrationRef::compose($key),
        ]);

        return array_values(array_filter(
            $this->orders($found),
            static fn (\WC_Order $o): bool => (new KalakaOrder($o))->isKalaka()
        ));
    }

    /**
     * Paid orders of ours since the time that no report has recorded: the meta filter finds ours, and the recorded ones
     * are left out here.
     *
     * @return list<\WC_Order>
     */
    public function unrecordedPaidSince(\DateTimeImmutable $since): array
    {
        $found = wc_get_orders([
            'limit' => -1,
            'status' => wc_get_is_paid_statuses(),
            'type' => 'shop_order',
            'date_paid' => '>=' . $since->getTimestamp(),
            'meta_key' => OrderMeta::MARKER,
            'meta_compare' => 'EXISTS',
        ]);

        return array_values(array_filter(
            $this->orders($found),
            static fn (\WC_Order $o): bool => !(new KalakaOrder($o))->isRecorded()
        ));
    }

    /**
     * @param mixed $found
     * @return list<\WC_Order>
     */
    private function orders(mixed $found): array
    {
        $orders = [];
        foreach (is_array($found) ? $found : [] as $order) {
            if ($order instanceof \WC_Order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }
}
