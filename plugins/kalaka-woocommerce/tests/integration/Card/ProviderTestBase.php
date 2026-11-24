<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\WooCommerce\OrderMeta;
use Kalaka\WooCommerce\ReconcileStep;
use Kalaka\WooCommerce\WooProvider;

/**
 * The card payment through real WooCommerce orders. The cases are written once; a concrete class runs them in
 * each of WooCommerce's order-storage modes.
 */
abstract class ProviderTestBase extends WooTestCase
{
    private WooProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->provider = new WooProvider();
    }

    /** @param array<string, string> $amounts registration code → amount, in the order of the lines */
    private function requestFor(array $keys, array $amounts = []): PaymentRequest
    {
        $lines = [];
        foreach ($keys as $key) {
            $lines[] = new RequestLine(
                $key,
                'participant',
                $this->huf($amounts[$key->code] ?? '10000'),
                'Teszt tábor: befizetés (' . $key->code . ')'
            );
        }

        return new PaymentRequest($lines, Currency::HUF, 'teszt@example.com');
    }

    /** @return list<\WC_Order> the orders of the plugin, oldest first */
    private function orders(): array
    {
        $orders = wc_get_orders([
            'limit' => -1,
            'status' => 'any',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_key' => OrderMeta::MARKER,
            'meta_compare' => 'EXISTS',
        ]);
        self::assertIsArray($orders);

        return array_values(array_filter($orders, static fn ($o): bool => $o instanceof \WC_Order));
    }

    private function onlyOrder(): \WC_Order
    {
        $orders = $this->orders();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    /** @return list<array<string, ?string>> the payment and refund lines of the test event */
    private function moneyLines(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE event_id = %d AND kind IN ('payment', 'refund') ORDER BY id",
                $wpdb->prefix . 'kalaka_ledger_lines',
                $this->event
            ),
            ARRAY_A
        );
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    private function payOrder(\WC_Order $order): void
    {
        $gateway = new StandInGateway();
        $result = $gateway->process_payment($order->get_id());
        self::assertSame('success', $result['result']);
    }

    public function testItCreatesOneGuestOrderWithOneItemPerLine(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');

        $url = $this->provider->payUrl($this->requestFor([$anna, $bela]), 'https://example.org/vissza/');

        $order = $this->onlyOrder();
        self::assertSame('pending', $order->get_status());
        self::assertSame(0, $order->get_customer_id());
        self::assertSame('HUF', $order->get_currency());
        self::assertSame('teszt@example.com', $order->get_billing_email());
        self::assertSame('', $order->get_billing_country());
        self::assertSame('', $order->get_billing_first_name());
        self::assertSame('20000', wc_format_decimal($order->get_total()));
        self::assertSame('0', wc_format_decimal($order->get_total_tax()));
        self::assertSame($order->get_checkout_payment_url(), $url);
        $items = array_values($order->get_items());
        self::assertCount(2, $items);
        self::assertSame('Teszt tábor: befizetés (00A0)', $items[0]->get_name());
        self::assertSame($this->event . ':00A0', $items[0]->get_meta('_kalaka_registration'));
        self::assertSame('participant', $items[0]->get_meta('_kalaka_payer'));
        self::assertSame($this->event . ':00A2', $items[1]->get_meta('_kalaka_registration'));
        self::assertSame('https://example.org/vissza/', $order->get_meta(OrderMeta::RETURN_URL));
        // Both items are the one hidden product.
        self::assertSame(
            (int) get_option(OrderMeta::PRODUCT_OPTION),
            $items[0] instanceof \WC_Order_Item_Product ? $items[0]->get_product_id() : 0
        );
        $product = wc_get_product((int) get_option(OrderMeta::PRODUCT_OPTION));
        self::assertInstanceOf(\WC_Product::class, $product);
        self::assertSame('hidden', $product->get_catalog_visibility());
        self::assertTrue($product->is_virtual());
    }

    public function testTheHiddenProductIsCreatedOnceAndRecreatedWhenTrashed(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $first = (int) get_option(OrderMeta::PRODUCT_OPTION);
        $this->provider->payUrl($this->requestFor([$anna], ['00A0' => '9000']), 'https://example.org/');
        self::assertSame($first, (int) get_option(OrderMeta::PRODUCT_OPTION));

        wp_trash_post($first);
        $this->provider->payUrl($this->requestFor([$anna], ['00A0' => '8000']), 'https://example.org/');

        self::assertNotSame($first, (int) get_option(OrderMeta::PRODUCT_OPTION));
    }

    public function testAnUnpaidOrderWithEqualLinesIsReused(): void
    {
        $anna = $this->owing('00A0');

        $first = $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/egy/');
        $second = $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/ketto/');

        self::assertSame($first, $second);
        $order = $this->onlyOrder();
        self::assertSame('pending', $order->get_status());
        self::assertSame('https://example.org/ketto/', $order->get_meta(OrderMeta::RETURN_URL));
    }

    public function testAFailedOrderWithEqualLinesIsReusedToo(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('failed');

        self::assertSame($first, $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/'));
        self::assertCount(1, $this->orders());
    }

    public function testAChangedAmountCancelsTheOldOrderAndCreatesANewOne(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');

        $second = $this->provider->payUrl($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');

        self::assertNotSame($first, $second);
        $orders = $this->orders();
        self::assertCount(2, $orders);
        self::assertSame('cancelled', $orders[0]->get_status());
        self::assertSame('pending', $orders[1]->get_status());
        self::assertSame('7000', wc_format_decimal($orders[1]->get_total()));
    }

    public function testAPaidOrderIsNeverTouchedWhenANewRequestIsMade(): void
    {
        $anna = $this->owing('00A0', '20000');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $this->payOrder($this->onlyOrder());

        $this->provider->payUrl($this->requestFor([$anna], ['00A0' => '5000']), 'https://example.org/');

        $orders = $this->orders();
        self::assertCount(2, $orders);
        self::assertContains($orders[0]->get_status(), ['processing', 'completed']);
        self::assertSame('pending', $orders[1]->get_status());
    }

    public function testAPaymentWritesOneLinePerItemExactlyOnceWhicheverHooksFire(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $this->provider->payUrl($this->requestFor([$anna, $bela]), 'https://example.org/');
        $order = $this->onlyOrder();

        $this->payOrder($order);
        $fresh = wc_get_order($order->get_id());
        self::assertInstanceOf(\WC_Order::class, $fresh);
        // All three hooks, and again.
        do_action('woocommerce_payment_complete', $order->get_id(), 'TX');
        do_action('woocommerce_order_status_processing', $order->get_id(), $fresh);
        do_action('woocommerce_order_status_completed', $order->get_id(), $fresh);

        $lines = $this->moneyLines();
        self::assertCount(2, $lines);
        self::assertSame('payment', $lines[0]['kind']);
        self::assertSame('-10000.00000000', $lines[0]['amount']);
        self::assertSame('woocommerce_order', $lines[0]['source_type']);
        self::assertSame((string) $order->get_id(), $lines[0]['source_key']);
        self::assertSame('00A0', $lines[0]['code']);
        self::assertSame('00A2', $lines[1]['code']);
        $keys = array_column($lines, 'idempotency_key');
        self::assertCount(2, array_unique($keys));
        self::assertStringStartsWith('online:woocommerce:order:' . $order->get_id() . ':', $keys[0]);
        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertNotSame('', (string) wc_get_order($order->get_id())->get_meta(OrderMeta::RECORDED));
    }

    public function testAFailedPaymentWritesNothingAndTheOrderStaysTheRequest(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        StandInGateway::$outcome = 'failed';
        (new StandInGateway())->process_payment($this->onlyOrder()->get_id());

        self::assertSame([], $this->moneyLines());
        self::assertSame('failed', $this->onlyOrder()->get_status());
        self::assertSame($first, $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/'));
    }

    public function testAnOrderThatLeavesPaidAfterwardsWritesNothing(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);

        $paid = wc_get_order($order->get_id());
        self::assertInstanceOf(\WC_Order::class, $paid);
        $paid->update_status('pending');
        $paid->update_status('processing');

        self::assertCount(1, $this->moneyLines());
    }

    public function testARefundByItemWritesRefundLinesKeyedByTheRefundAndItem(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $this->provider->payUrl($this->requestFor([$anna, $bela]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);
        $items = array_values($order->get_items());

        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 3000,
            'line_items' => [$items[1]->get_id() => ['qty' => 0, 'refund_total' => 3000, 'refund_tax' => []]],
        ]);
        self::assertInstanceOf(\WC_Order_Refund::class, $refund);

        $lines = $this->moneyLines();
        self::assertCount(3, $lines);
        self::assertSame('refund', $lines[2]['kind']);
        self::assertSame('3000.00000000', $lines[2]['amount']);
        self::assertSame('00A2', $lines[2]['code']);
        self::assertSame(
            'online:woocommerce:refund:' . $refund->get_id() . ':' . $items[1]->get_id(),
            $lines[2]['idempotency_key']
        );
        // A repeat of the event writes nothing more.
        do_action('woocommerce_order_refunded', $order->get_id(), $refund->get_id());
        self::assertCount(3, $this->moneyLines());
    }

    public function testAnAmountOnlyRefundOnAOneItemOrderGoesToThatItem(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 2500]);

        $lines = $this->moneyLines();
        self::assertCount(2, $lines);
        self::assertSame('refund', $lines[1]['kind']);
        self::assertSame('2500.00000000', $lines[1]['amount']);
        self::assertSame('00A0', $lines[1]['code']);
        self::assertStringNotContainsString('"allocated":true', $this->refundAudit());
    }

    public function testAnAmountOnlyRefundOnATwoItemOrderIsAllocatedInItemOrderUpToWhatIsLeft(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $request = $this->requestFor([$anna, $bela], ['00A0' => '4000', '00A2' => '6000']);
        $this->provider->payUrl($request, 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 5000]);
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 3000]);

        $refunds = array_values(array_filter(
            $this->moneyLines(),
            static fn (array $l): bool => $l['kind'] === 'refund'
        ));
        // 5000: the first item's 4000, then 1000 of the second. Then 3000 more, all from the second (5000 left).
        self::assertSame(
            [['00A0', '4000.00000000'], ['00A2', '1000.00000000'], ['00A2', '3000.00000000']],
            array_map(static fn (array $l): array => [$l['code'], $l['amount']], $refunds)
        );
        self::assertStringContainsString('"allocated":true', $this->refundAudit());
    }

    private function refundAudit(): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            "SELECT GROUP_CONCAT(changes) FROM {$wpdb->prefix}kalaka_audit_entries"
            . " WHERE action = 'kalaka/record-online-refund'"
        );
    }

    public function testLatestOfMapsTheOrdersStates(): void
    {
        $anna = $this->owing('00A0');
        self::assertNull($this->provider->latestOf($anna));

        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        self::assertSame(OpenPaymentState::Pending, $this->provider->latestOf($anna)?->state);

        $order->update_status('failed');
        self::assertSame(OpenPaymentState::Failed, $this->provider->latestOf($anna)?->state);

        $order->update_status('on-hold');
        self::assertSame(OpenPaymentState::Processing, $this->provider->latestOf($anna)?->state);

        $order->update_status('cancelled');
        self::assertNull($this->provider->latestOf($anna));

        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $this->payOrder($this->orders()[1]);
        self::assertSame(OpenPaymentState::Paid, $this->provider->latestOf($anna)?->state);

        wc_create_refund(['order_id' => $this->orders()[1]->get_id(), 'amount' => 10000]);
        self::assertNull($this->provider->latestOf($anna), 'Refunded in full is no request.');
    }

    public function testTheReconcileStepRecordsAPaidOrderAHookMissed(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        remove_all_actions('woocommerce_payment_complete');
        remove_all_actions('woocommerce_order_status_processing');
        remove_all_actions('woocommerce_order_status_completed');
        $this->payOrder($order);
        self::assertSame([], $this->moneyLines());

        (new ReconcileStep())->run();
        (new ReconcileStep())->run();

        self::assertCount(1, $this->moneyLines());
    }

    public function testAGuestCanOpenTheOrderPayPageAndAWrongKeyCannot(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        wp_set_current_user(0);
        self::assertTrue(current_user_can('pay_for_order', $order->get_id()));
        self::assertTrue($order->needs_payment());
        parse_str((string) wp_parse_url($order->get_checkout_payment_url(), PHP_URL_QUERY), $query);
        self::assertSame($order->get_order_key(), $query['key'] ?? null);
        self::assertSame('true', $query['pay_for_order'] ?? null);

        self::assertStringContainsString('order_review', $this->payPage($order, $order->get_order_key()));
        self::assertStringNotContainsString('order_review', $this->payPage($order, 'wc_order_wrong'));
    }

    private function payPage(\WC_Order $order, string $key): string
    {
        global $wp;
        $_GET['pay_for_order'] = 'true';
        $_GET['key'] = $key;
        $wp->query_vars['order-pay'] = (string) $order->get_id();
        ob_start();
        \WC_Shortcode_Checkout::output([]);

        return (string) ob_get_clean();
    }

    public function testAcceptsNeedsTheShopCurrencyAndAnOnlineGateway(): void
    {
        self::assertTrue($this->provider->accepts(Currency::HUF));

        update_option('woocommerce_currency', 'EUR');
        self::assertFalse($this->provider->accepts(Currency::HUF));
    }

    public function testTheGatewayReturnsTheRegistrantToTheStoredAddress(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna]), 'https://example.org/vissza/');
        $order = $this->onlyOrder();

        self::assertSame('https://example.org/vissza/', (new StandInGateway())->get_return_url($order));
        $other = wc_create_order();
        self::assertInstanceOf(\WC_Order::class, $other);
        self::assertNotSame('https://example.org/vissza/', (new StandInGateway())->get_return_url($other));
    }

    public function testRecordingIsRegardlessOfTheAmountAndAMoneyValueIsExact(): void
    {
        $anna = $this->owing('00A0');
        $this->provider->payUrl($this->requestFor([$anna], ['00A0' => '12345']), 'https://example.org/');
        $this->payOrder($this->onlyOrder());

        $lines = $this->moneyLines();
        self::assertSame('-12345.00000000', $lines[0]['amount']);
        self::assertTrue(Money::of('12345', Currency::HUF)->isPositive());
    }
}
