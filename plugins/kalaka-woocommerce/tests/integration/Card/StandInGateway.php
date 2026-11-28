<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

/**
 * A test-only gateway that stands in for a card gateway: `process_payment()` completes the payment the way a
 * real gateway does (`payment_complete()`), or fails the order, as `$outcome` says.
 */
final class StandInGateway extends \WC_Payment_Gateway
{
    public const ID = 'kalaka_stand_in';

    /** `paid` or `failed`: what the next `process_payment()` does. */
    public static string $outcome = 'paid';

    public function __construct()
    {
        $this->id = self::ID;
        $this->method_title = 'Stand-in';
        $this->enabled = 'yes';
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];
    }

    /**
     * @param int $order_id
     * @return array<string, string>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WooCommerce's gateway method
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['result' => 'failure'];
        }
        if (self::$outcome === 'failed') {
            $order->update_status('failed');

            return ['result' => 'failure'];
        }
        $order->payment_complete('TX-' . $order_id);

        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WooCommerce's gateway method
    public function process_refund($order_id, $amount = null, $reason = ''): bool
    {
        return true;
    }
}
