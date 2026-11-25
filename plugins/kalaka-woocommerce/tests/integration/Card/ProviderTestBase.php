<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\LogCapture;
use Kalaka\WooCommerce\OrderMeta;
use Kalaka\WooCommerce\ReconcileStep;
use Kalaka\WooCommerce\WooProvider;

/**
 * The card payment through real WooCommerce orders. The cases are written once; a concrete class runs them in
 * each of WooCommerce's order-storage modes.
 */
abstract class ProviderTestBase extends WooTestCase
{
    use LogCapture;

    private WooProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->provider = new WooProvider();
        // The registrant's browser, as WooCommerce would store it on a new order.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Teszt Bongeszo/1.0';
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

    private function payUrlFor(PaymentRequest $request, string $returnUrl): string
    {
        return $this->provider->payUrl(PaymentProviderInput::forStart($request, $returnUrl));
    }

    private function latestFor(RegistrationKey $key): ?OpenPayment
    {
        return $this->provider->latestOf(PaymentProviderInput::forLookup($key, Currency::HUF));
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

    /** The paid hooks of WooCommerce gone, as when a request dies before they run. */
    private function missTheHooks(): void
    {
        remove_all_actions('woocommerce_payment_complete');
        remove_all_actions('woocommerce_order_status_processing');
        remove_all_actions('woocommerce_order_status_completed');
    }

    /** @return list<array<string, ?string>> */
    private function refundLines(): array
    {
        return array_values(array_filter($this->moneyLines(), static fn (array $l): bool => $l['kind'] === 'refund'));
    }

    private function freshOrder(\WC_Order $order): \WC_Order
    {
        $fresh = wc_get_order($order->get_id());
        self::assertInstanceOf(\WC_Order::class, $fresh);

        return $fresh;
    }

    public function testItCreatesOneGuestOrderWithOneItemPerLine(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');

        $url = $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/vissza/');

        $order = $this->onlyOrder();
        self::assertSame('pending', $order->get_status());
        self::assertSame(0, $order->get_customer_id());
        self::assertSame('HUF', $order->get_currency());
        self::assertSame('teszt@example.com', $order->get_billing_email());
        self::assertSame('', $order->get_billing_country());
        self::assertSame('', $order->get_billing_first_name());
        // Nothing of the registrant's browser.
        self::assertSame('', $order->get_customer_ip_address());
        self::assertSame('', $order->get_customer_user_agent());
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
        self::assertSame('private', $product->get_status());
    }

    public function testTheOrderHasTheRequestsCurrencyWhateverTheShopsIsAtThatMoment(): void
    {
        $anna = $this->owing('00A0');
        update_option('woocommerce_currency', 'EUR');

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        self::assertSame('HUF', $this->freshOrder($this->onlyOrder())->get_currency());
    }

    public function testTheHiddenProductCannotBeBoughtEvenByAnAdministrator(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $product = wc_get_product((int) get_option(OrderMeta::PRODUCT_OPTION));
        self::assertInstanceOf(\WC_Product::class, $product);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // An administrator may see a private product, so only the filter makes this false.
        self::assertTrue(current_user_can('edit_post', $product->get_id()));
        self::assertFalse($product->is_purchasable());
        self::assertFalse(WC()->cart->add_to_cart($product->get_id()));
        // And it still pays as an item of the order.
        $this->payOrder($this->onlyOrder());
        self::assertCount(1, $this->moneyLines());
    }

    public function testTheHiddenProductIsCreatedOnceAndRecreatedWhenTrashed(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $first = (int) get_option(OrderMeta::PRODUCT_OPTION);
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '9000']), 'https://example.org/');
        self::assertSame($first, (int) get_option(OrderMeta::PRODUCT_OPTION));

        wp_trash_post($first);
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '8000']), 'https://example.org/');

        self::assertNotSame($first, (int) get_option(OrderMeta::PRODUCT_OPTION));
    }

    public function testAnUnpaidOrderWithEqualLinesIsReused(): void
    {
        $anna = $this->owing('00A0');

        $first = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/egy/');
        $second = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/ketto/');

        self::assertSame($first, $second);
        $order = $this->onlyOrder();
        self::assertSame('pending', $order->get_status());
        self::assertSame('https://example.org/ketto/', $order->get_meta(OrderMeta::RETURN_URL));
    }

    public function testAFailedOrderWithEqualLinesIsReusedToo(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('failed');

        self::assertSame($first, $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/'));
        self::assertCount(1, $this->orders());
    }

    public function testAChangedAmountCancelsTheOldOrderAndCreatesANewOne(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        $second = $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');

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
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payOrder($this->onlyOrder());

        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '5000']), 'https://example.org/');

        $orders = $this->orders();
        self::assertCount(2, $orders);
        self::assertContains($orders[0]->get_status(), ['processing', 'completed']);
        self::assertSame('pending', $orders[1]->get_status());
    }

    public function testAPaymentWritesOneLinePerItemExactlyOnceWhicheverHooksFire(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'payment_recorded',
            'version' => 1,
            'enabled' => 1,
            'body_id' => null,
            'layout' => 1,
        ]);
        $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/');
        $order = $this->onlyOrder();

        $this->payOrder($order);
        $fresh = $this->freshOrder($order);
        // Each hook in turn, as if the others never ran: the recorded mark is taken off before each, so every
        // hook builds the report itself and the ledger's key, not the mark, is what makes it a replay.
        [$log] = $this->captureLog(function () use ($order, $fresh): void {
            foreach (
                [
                    ['woocommerce_payment_complete', [$order->get_id(), 'TX']],
                    ['woocommerce_order_status_processing', [$order->get_id(), $fresh]],
                    ['woocommerce_order_status_completed', [$order->get_id(), $fresh]],
                ] as [$hook, $args]
            ) {
                $again = $this->freshOrder($order);
                $again->delete_meta_data(OrderMeta::RECORDED);
                $again->save_meta_data();
                do_action($hook, ...$args);
                self::assertNotSame('', (string) $this->freshOrder($order)->get_meta(OrderMeta::RECORDED), $hook);
            }
        });

        self::assertSame('', $log, 'No hook failed against the stored lines.');
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
        self::assertSame(
            2,
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_deliveries WHERE mail = 'payment_recorded'"
            ),
            'One mail per line, not per hook.'
        );
    }

    public function testAFailedPaymentWritesNothingAndTheOrderStaysTheRequest(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        StandInGateway::$outcome = 'failed';
        (new StandInGateway())->process_payment($this->onlyOrder()->get_id());

        self::assertSame([], $this->moneyLines());
        self::assertSame('failed', $this->onlyOrder()->get_status());
        self::assertSame($first, $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/'));
    }

    public function testAnOrderThatLeavesPaidAfterwardsWritesNothing(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
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
        $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/');
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
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
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
        $this->payUrlFor($request, 'https://example.org/');
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
        self::assertNull($this->latestFor($anna));

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        self::assertSame(OpenPaymentState::Pending, $this->latestFor($anna)?->state);

        $order->update_status('failed');
        self::assertSame(OpenPaymentState::Failed, $this->latestFor($anna)?->state);

        $order->update_status('on-hold');
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);

        $order->update_status('cancelled');
        self::assertNull($this->latestFor($anna));

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->missTheHooks();
        $this->payOrder($this->orders()[1]);
        self::assertSame(OpenPaymentState::Paid, $this->latestFor($anna)?->state, 'Paid, not recorded yet.');
        self::assertSame((string) $this->orders()[1]->get_id(), $this->latestFor($anna)->requestRef);

        (new ReconcileStep())->run();
        self::assertNull($this->latestFor($anna), 'Recorded: the request is over.');

        wc_create_refund(['order_id' => $this->orders()[1]->get_id(), 'amount' => 10000]);
        self::assertNull($this->latestFor($anna), 'Refunded in full is no request.');
    }

    public function testTheReconcileStepRecordsAPaidOrderAHookMissed(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
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

    public function testAPaidOrderWithNoReadableItemIsReportedAndNotMarkedRecorded(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        foreach ($order->get_items() as $item) {
            $item->update_meta_data('_kalaka_registration', 'not a registration');
            $item->save();
        }

        [$log] = $this->captureLog(function () use ($order): void {
            $this->payOrder($order);
        });

        self::assertSame([], $this->moneyLines());
        self::assertSame('', (string) $this->freshOrder($order)->get_meta(OrderMeta::RECORDED));
        self::assertStringContainsString('A paid Kaláka order has no readable item', $log);
    }

    public function testAGuestCanOpenTheOrderPayPageAndAWrongKeyCannot(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
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
        $input = PaymentProviderInput::forLookup($this->owing('00A0'), Currency::HUF);
        self::assertTrue($this->provider->accepts($input));

        update_option('woocommerce_currency', 'EUR');
        self::assertFalse($this->provider->accepts($input));
    }

    public function testTheGatewayReturnsTheRegistrantToTheStoredAddress(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/vissza/');
        $order = $this->onlyOrder();

        self::assertSame('https://example.org/vissza/', (new StandInGateway())->get_return_url($order));
        $other = wc_create_order();
        self::assertInstanceOf(\WC_Order::class, $other);
        self::assertNotSame('https://example.org/vissza/', (new StandInGateway())->get_return_url($other));
    }

    public function testRecordingIsRegardlessOfTheAmountAndAMoneyValueIsExact(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '12345']), 'https://example.org/');
        $this->payOrder($this->onlyOrder());

        $lines = $this->moneyLines();
        self::assertSame('-12345.00000000', $lines[0]['amount']);
        self::assertTrue(Money::of('12345', Currency::HUF)->isPositive());
    }

    public function testARefundThatFullyRefundsTheOrderIsRecordedAndTheBalanceOwesAgain(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);
        self::assertSame('0', $this->balance($anna));

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10000]);

        // The order is `refunded` when WooCommerce tells us about the refund.
        self::assertSame('refunded', $this->freshOrder($order)->get_status());
        $lines = $this->moneyLines();
        self::assertSame(['payment', 'refund'], array_column($lines, 'kind'));
        self::assertSame('10000.00000000', $lines[1]['amount']);
        self::assertSame('10000', $this->balance($anna));
    }

    public function testTheLastOfSeveralPartialRefundsIsRecordedToo(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 6000]);
        self::assertNotSame('refunded', $this->freshOrder($order)->get_status());
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 4000]);

        self::assertSame('refunded', $this->freshOrder($order)->get_status());
        self::assertSame(
            ['6000.00000000', '4000.00000000'],
            array_column($this->refundLines(), 'amount')
        );
        self::assertSame('10000', $this->balance($anna));
    }

    public function testAnOrderMovedToRefundedByHandIsRecordedAsRefunded(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);

        // WooCommerce makes the refund itself, after the status is already `refunded`.
        $this->freshOrder($order)->update_status('refunded');

        self::assertSame(['payment', 'refund'], array_column($this->moneyLines(), 'kind'));
        self::assertSame('10000', $this->balance($anna));
    }

    public function testAFullRefundOfAnOrderWhosePaymentHookWasMissedRecordsThePaymentAndTheRefund(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->missTheHooks();
        $this->payOrder($order);
        self::assertSame([], $this->moneyLines());

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10000]);

        self::assertSame(['payment', 'refund'], array_column($this->moneyLines(), 'kind'));
        self::assertNotSame('', (string) $this->freshOrder($order)->get_meta(OrderMeta::RECORDED));
        self::assertSame('10000', $this->balance($anna));
    }

    public function testTheReconcileStepRecordsAPaidOrderThatWasRefundedInFullBeforeItWasRecorded(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->missTheHooks();
        remove_all_actions('woocommerce_order_refunded');
        $this->payOrder($order);
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10000]);
        self::assertSame([], $this->moneyLines());

        (new ReconcileStep())->run();
        (new ReconcileStep())->run();

        self::assertSame(['payment', 'refund'], array_column($this->moneyLines(), 'kind'));
        self::assertSame('10000', $this->balance($anna));
    }

    public function testTheReconcileStepRecordsARefundWhoseRecordingFailedEarlier(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);
        remove_all_actions('woocommerce_order_refunded');
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 3000]);
        self::assertSame([], $this->refundLines());

        (new ReconcileStep())->run();
        (new ReconcileStep())->run();

        self::assertSame(['3000.00000000'], array_column($this->refundLines(), 'amount'));
        self::assertCount(2, $this->moneyLines());
    }

    public function testTheReconcileWindowIs30DaysOfTheRunnersClock(): void
    {
        $old = $this->owing('00A0');
        $recent = $this->register('00A2');
        $this->payUrlFor($this->requestFor([$old]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$recent]), 'https://example.org/');
        $this->missTheHooks();
        $now = Runner::current()->clock()->now();
        foreach ($this->orders() as $i => $order) {
            $this->payOrder($order);
            $paid = $this->freshOrder($order);
            $paid->set_date_paid($now->modify($i === 0 ? '-31 days' : '-29 days')->getTimestamp());
            $paid->save();
        }

        (new ReconcileStep())->run();

        self::assertSame(['00A2'], array_column($this->moneyLines(), 'code'));
    }

    public function testAnOnHoldOrderBlocksWhateverTheNewestOrderIs(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('on-hold');
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);

        // A newer pending order of the same registration does not hide it.
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');

        self::assertCount(2, $this->orders());
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
    }

    public function testAnOrderCancelledByALaterClickThatIsPaidAnywayIsRecordedAndShowsAsAnOverpayment(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');
        $orders = $this->orders();
        self::assertSame('cancelled', $orders[0]->get_status());

        // The registrant had the first order's pay page open and pays it.
        $this->payOrder($orders[0]);
        $this->payOrder($this->freshOrder($orders[1]));

        self::assertSame(['-10000.00000000', '-7000.00000000'], array_column($this->moneyLines(), 'amount'));
        self::assertSame('-7000', $this->balance($anna), 'Recorded, never gated: overpaid.');
    }

    public function testARefundThatNamesItemsButAMoreThanTheyAddUpToIsRecordedInFull(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $request = $this->requestFor([$anna, $bela], ['00A0' => '4000', '00A2' => '6000']);
        $this->payUrlFor($request, 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);
        $items = array_values($order->get_items());

        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 5000,
            'line_items' => [$items[0]->get_id() => ['qty' => 0, 'refund_total' => 3000, 'refund_tax' => []]],
        ]);

        // The named 3000, then the excess 2000 in item order: 1000 of the first item's room, 1000 of the second.
        self::assertSame(
            [['00A0', '4000.00000000'], ['00A2', '1000.00000000']],
            array_map(static fn (array $l): array => [$l['code'], $l['amount']], $this->refundLines())
        );
        self::assertStringContainsString('"allocated":true', $this->refundAudit());
    }

    public function testARefundWhoseItemLinesAllHaveNoAmountIsSplitAndMarkedAsAllocated(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $request = $this->requestFor([$anna, $bela], ['00A0' => '4000', '00A2' => '6000']);
        $this->payUrlFor($request, 'https://example.org/');
        $order = $this->onlyOrder();
        $this->payOrder($order);
        $items = array_values($order->get_items());

        // A quantity was entered but the amount was typed in the total box.
        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 5000,
            'line_items' => [$items[1]->get_id() => ['qty' => 1, 'refund_total' => 0, 'refund_tax' => []]],
        ]);

        self::assertSame(
            [['00A0', '4000.00000000'], ['00A2', '1000.00000000']],
            array_map(static fn (array $l): array => [$l['code'], $l['amount']], $this->refundLines())
        );
        self::assertStringContainsString('"allocated":true', $this->refundAudit());
    }
}
