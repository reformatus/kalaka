<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

/** The page a gateway sends the registrant to after paying: for an order of ours, where the registrant came from. */
final class ReturnAddress
{
    /**
     * @internal the `woocommerce_get_return_url` filter
     * @param mixed $url
     * @param mixed $order
     * @return mixed
     */
    public function of($url, $order)
    {
        if (!$order instanceof \WC_Order || $order->get_meta(OrderMeta::MARKER, true) === '') {
            return $url;
        }
        $stored = (string) $order->get_meta(OrderMeta::RETURN_URL, true);

        return $stored === '' ? $url : $stored;
    }
}
