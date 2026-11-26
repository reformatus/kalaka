<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Payments\Api\CardPaymentInFlight;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\LogCapture;
use Kalaka\WooCommerce\BillingDetails;
use Kalaka\WooCommerce\BillingSource;
use Kalaka\WooCommerce\CardSurcharge;
use Kalaka\WooCommerce\OrderMeta;
use Kalaka\WooCommerce\ReconcileStep;
use Kalaka\WooCommerce\WooProvider;
use Kalaka\WooCommerce\NoBilling;

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
        // A card payment starts only on a published event.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['state' => 'published'], ['id' => $this->event]);
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

    /** A stored amount as the plain decimal it is: `206.00000000` is `206`. */
    private function plain(string $amount): string
    {
        return rtrim(rtrim(wc_format_decimal($amount), '0'), '.');
    }

    /** @return list<string> the statuses of the plugin's orders, oldest first */
    private function statuses(): array
    {
        return array_map(static fn (\WC_Order $o): string => $o->get_status(), $this->orders());
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

    /** The registration's owner account, as an account sets it on sign-up. */
    private function ownedBy(RegistrationKey $key, int $user): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['owner_user_id' => $user],
            ['event_id' => $key->eventId, 'code' => $key->code]
        );
        // The repositories keep the rows they read for the request.
        ObjectCache::clear();
    }

    /**
     * The visitor as the card start sees it: a REST post with no `wp_rest` nonce, so the current user is 0 whatever
     * the cookie says; the logged-in cookie is there when the visitor is logged in.
     */
    private function visitAs(int $user): void
    {
        wp_set_current_user(0);
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        if ($user > 0) {
            $token = \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);
            $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user, time() + HOUR_IN_SECONDS, 'logged_in', $token);
        }
    }

    public function testAnOrderBelongsToTheOwnerAccountWhenThatUserIsThePayingVisitor(): void
    {
        $anna = $this->owing('00A0');
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $this->ownedBy($anna, $user);
        $this->visitAs($user);

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        $order = $this->onlyOrder();
        self::assertSame($user, $order->get_customer_id());
        // The cookie was read, nobody was logged in by it.
        self::assertSame(0, get_current_user_id());
        self::assertSame('teszt@example.com', $order->get_billing_email());
        // It is in the user's WooCommerce orders (My Account), and the user can pay it without the e-mail step.
        self::assertSame([$order->get_id()], array_map(
            static fn ($o): int => $o instanceof \WC_Order ? $o->get_id() : 0,
            (array) wc_get_orders(['customer_id' => $user, 'limit' => -1, 'status' => 'any'])
        ));
        wp_set_current_user($user);
        self::assertTrue(current_user_can('pay_for_order', $order->get_id()));
        self::assertStringContainsString('order_review', $this->payPage($order, $order->get_order_key()));
    }

    public function testAnOrderIsAGuestOrderWhenTheOwnersRegistrantIsLoggedOut(): void
    {
        $anna = $this->owing('00A0');
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $this->ownedBy($anna, $user);
        $this->visitAs(0);

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        $order = $this->onlyOrder();
        self::assertSame(0, $order->get_customer_id());
        // The logged-out registrant reaches WooCommerce's pay page.
        wp_set_current_user(0);
        self::assertStringContainsString('order_review', $this->payPage($order, $order->get_order_key()));
    }

    public function testAnOrderIsAGuestOrderWhenAnotherUserIsLoggedIn(): void
    {
        $anna = $this->owing('00A0');
        $owner = self::factory()->user->create(['role' => 'subscriber']);
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $this->ownedBy($anna, $owner);
        $this->visitAs($other);

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        self::assertSame(0, $this->onlyOrder()->get_customer_id());
    }

    public function testAnOrderIsAGuestOrderWhenTheRegistrationHasNoOwnerOrTheCookieIsForged(): void
    {
        $anna = $this->owing('00A0');
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $this->visitAs($user);
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        self::assertSame(0, $this->onlyOrder()->get_customer_id());

        // An owner, and a cookie that names the owner but is not signed by the site.
        $this->ownedBy($anna, $user);
        wp_set_current_user(0);
        $_COOKIE[LOGGED_IN_COOKIE] = $user . '|' . (time() + 3600) . '|token|hmac';
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        self::assertSame([0, 0], array_map(static fn (\WC_Order $o): int => $o->get_customer_id(), $this->orders()));
        unset($_COOKIE[LOGGED_IN_COOKIE]);
    }

    public function testSeveralLinesAreOneOwnersOrderOnlyWhenEveryLineHasThatOwner(): void
    {
        $anna = $this->owing('00A0');
        $bela = $this->register('00A2');
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $this->ownedBy($anna, $user);
        $this->ownedBy($bela, $user);
        $this->visitAs($user);
        $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/');
        self::assertSame($user, $this->onlyOrder()->get_customer_id());

        // Two owners, then one registration without an owner: a guest order, whoever pays.
        $this->ownedBy($bela, $other);
        $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$bela, $anna]), 'https://example.org/');
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET owner_user_id = NULL WHERE code = %s',
            $wpdb->prefix . 'kalaka_registrations',
            $bela->code
        ));
        $this->payUrlFor($this->requestFor([$anna, $bela]), 'https://example.org/');
        self::assertSame(
            [$user, 0, 0, 0],
            array_map(static fn (\WC_Order $o): int => $o->get_customer_id(), $this->orders())
        );
    }

    public function testTheBillingSourcesDetailsAreSetOnTheOrder(): void
    {
        $anna = $this->owing('00A0');
        $source = new class implements BillingSource {
            /** @var list<string> */
            public array $asked = [];

            public function of(RegistrationKey $registration): ?BillingDetails
            {
                $this->asked[] = $registration->code;

                return new BillingDetails('Minta Anna', 'hu', '1234', 'Mintaváros', 'Minta utca 1.');
            }
        };
        $provider = new WooProvider(billing: $source);

        $provider->payUrl(PaymentProviderInput::forStart($this->requestFor([$anna]), 'https://example.org/'));

        $order = $this->onlyOrder();
        self::assertSame(['00A0'], $source->asked);
        self::assertSame('Minta Anna', $order->get_billing_first_name());
        self::assertSame('', $order->get_billing_last_name());
        self::assertSame('HU', $order->get_billing_country());
        self::assertSame('1234', $order->get_billing_postcode());
        self::assertSame('Mintaváros', $order->get_billing_city());
        self::assertSame('Minta utca 1.', $order->get_billing_address_1());
        self::assertSame('teszt@example.com', $order->get_billing_email());
    }

    public function testOnlyTheNonEmptyDetailsAreSet(): void
    {
        $anna = $this->owing('00A0');
        $source = new class implements BillingSource {
            public function of(RegistrationKey $registration): ?BillingDetails
            {
                return new BillingDetails(null, 'HU', '', 'Mintaváros', null);
            }
        };
        (new WooProvider(billing: $source))->payUrl(
            PaymentProviderInput::forStart($this->requestFor([$anna]), 'https://example.org/')
        );

        $order = $this->onlyOrder();
        self::assertSame('', $order->get_billing_first_name());
        self::assertSame('HU', $order->get_billing_country());
        self::assertSame('', $order->get_billing_postcode());
        self::assertSame('Mintaváros', $order->get_billing_city());
        self::assertSame('', $order->get_billing_address_1());
        self::assertSame('teszt@example.com', $order->get_billing_email());
    }

    public function testASourceThatKnowsNobodyLeavesTheBillingEmailOnly(): void
    {
        $anna = $this->owing('00A0');
        $source = new class implements BillingSource {
            public function of(RegistrationKey $registration): ?BillingDetails
            {
                return null;
            }
        };
        foreach ([new WooProvider(billing: $source), new WooProvider(billing: new NoBilling())] as $provider) {
            $provider->payUrl(PaymentProviderInput::forStart($this->requestFor([$anna]), 'https://example.org/'));
        }

        foreach ($this->orders() as $order) {
            self::assertSame('teszt@example.com', $order->get_billing_email());
            self::assertSame('', $order->get_billing_first_name());
            self::assertSame('', $order->get_billing_country());
            self::assertSame('', $order->get_billing_city());
            self::assertSame('', $order->get_billing_address_1());
        }
        self::assertCount(2, $this->orders());
    }

    public function testABillingCountryIsTwoLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BillingDetails(country: 'Hungary');
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
        // A declined card: the next start replaces the failed order.
        self::assertNotSame($first, $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/'));
        self::assertSame(['cancelled', 'pending'], $this->statuses());
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

    public function testAnOnHoldOrderRefusesTheStartAndCancelsNothingWhateverTheNewestOrderIs(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('on-hold');
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
        self::assertSame(['on-hold'], $this->statuses());

        // A newer pending order of the same registration does not hide it.
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
        self::assertSame(['on-hold', 'pending'], $this->statuses());
    }

    /** The start as core makes it: refused as in flight, with no order cancelled or created. */
    private function assertStartRefused(RegistrationKey $key): void
    {
        $before = array_map(
            static fn (\WC_Order $o): string => $o->get_id() . ':' . $o->get_status(),
            $this->orders()
        );
        try {
            (new CardPayments())->start($key);
            self::fail('The start was not refused.');
        } catch (CardPaymentInFlight $e) {
            self::assertSame('kalaka_card_payment_in_flight', $e->errorCode());
        }
        self::assertSame(
            $before,
            array_map(static fn (\WC_Order $o): string => $o->get_id() . ':' . $o->get_status(), $this->orders())
        );
    }

    public function testAPendingOrderIsCancelledAndANewOneMadeWhateverItsLines(): void
    {
        $anna = $this->owing('00A0');
        $first = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/egy/');

        $second = $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/ketto/');

        self::assertNotSame($first, $second);
        self::assertSame(['cancelled', 'pending'], $this->statuses());
        self::assertSame('https://example.org/ketto/', $this->orders()[1]->get_meta(OrderMeta::RETURN_URL));
    }

    public function testTwoQuickStartsLeaveExactlyOneUnpaidOrder(): void
    {
        $anna = $this->owing('00A0');

        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        self::assertSame(['cancelled', 'cancelled', 'pending'], $this->statuses());
    }

    public function testACancelledOrderIsNoRequestAndDoesNotBlock(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('cancelled');

        self::assertNull($this->latestFor($anna));
        $url = (new CardPayments())->start($anna);

        self::assertStringContainsString('pay_for_order=true', $url);
        self::assertSame(['cancelled', 'pending'], $this->statuses());
    }

    public function testAPaidAndRecordedOrderDoesNotBlockANewStart(): void
    {
        $anna = $this->owing('00A0', '20000');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payOrder($this->onlyOrder());
        self::assertNotSame('', (string) $this->freshOrder($this->onlyOrder())->get_meta(OrderMeta::RECORDED));

        $url = (new CardPayments())->start($anna);

        self::assertStringContainsString('pay_for_order=true', $url);
        self::assertCount(2, $this->orders());
        self::assertSame('pending', $this->orders()[1]->get_status());
    }

    public function testAPaidOrderNotYetRecordedRefusesTheStart(): void
    {
        $anna = $this->owing('00A0', '20000');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->missTheHooks();
        $this->payOrder($this->onlyOrder());

        $this->assertStartRefused($anna);
    }

    public function testAnOrderInAStatusKalakaDoesNotHandleRefusesTheStartAndCancelsNothing(): void
    {
        register_post_status('wc-kalaka-test', ['label' => 'Egyedi', 'public' => false]);
        add_filter('wc_order_statuses', static function (array $statuses): array {
            $statuses['wc-kalaka-test'] = 'Egyedi';

            return $statuses;
        });
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->onlyOrder()->update_status('kalaka-test');
        self::assertSame('kalaka-test', $this->freshOrder($this->onlyOrder())->get_status());

        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
        // Whichever order is the newest.
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');
        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
        self::assertSame(['kalaka-test', 'pending'], $this->statuses());
    }

    public function testAnOrderInTheDraftStatusOfWooCommerceRefusesTheStart(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        $order->set_status('checkout-draft');
        $order->save();
        self::assertSame('checkout-draft', $this->freshOrder($order)->get_status());

        self::assertSame(OpenPaymentState::Processing, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
    }

    public function testAnOrderPaidAnywayWhileANewerOneIsPendingStillBlocksUntilItIsRecorded(): void
    {
        $anna = $this->owing('00A0', '20000');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7000']), 'https://example.org/');
        $this->missTheHooks();
        $this->payOrder($this->orders()[0]);

        self::assertSame(OpenPaymentState::Paid, $this->latestFor($anna)?->state);
        $this->assertStartRefused($anna);
    }

    private function surcharge(string $percent, ?string $fixed = null): void
    {
        (new CardSurcharge())->set($this->event, $percent, $fixed);
    }

    /** @return list<\WC_Order_Item_Fee> */
    private function feeLines(\WC_Order $order): array
    {
        $fees = array_values($order->get_items('fee'));

        return array_values(array_filter($fees, static fn ($f): bool => $f instanceof \WC_Order_Item_Fee));
    }

    public function testAnEventWithoutASurchargeAddsNoFeeLine(): void
    {
        $anna = $this->owing('00A0');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');

        self::assertSame([], $this->feeLines($this->onlyOrder()));
        self::assertSame('10000', wc_format_decimal($this->onlyOrder()->get_total()));
    }

    public function testTheSurchargeIsOneTaxlessFeeLineRoundedUpAndTheItemKeepsTheRequestedAmount(): void
    {
        $anna = $this->owing('00A0');
        $this->surcharge('1.5', '100');
        // 7001 x 1.5 % = 105.015, plus 100, rounded up to 206.
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '7001']), 'https://example.org/');

        $order = $this->freshOrder($this->onlyOrder());
        $fees = $this->feeLines($order);
        self::assertCount(1, $fees);
        self::assertSame('Kártyás fizetés díja', $fees[0]->get_name());
        self::assertSame('206', $this->plain($fees[0]->get_total()));
        self::assertSame('none', $fees[0]->get_tax_status());
        self::assertSame('7001', $this->plain(array_values($order->get_items())[0]->get_total()));
        self::assertSame('7207', wc_format_decimal($order->get_total()));
        self::assertSame('0', wc_format_decimal($order->get_total_tax()));
    }

    public function testASurchargeOfOnlyAPercentOrOnlyAFixedAmountAndOffAndOtherEvents(): void
    {
        $anna = $this->owing('00A0');
        $surcharge = new CardSurcharge();
        $surcharge->set($this->event, '10', null);
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        self::assertSame('11000', wc_format_decimal($this->freshOrder($this->orders()[0])->get_total()));

        $surcharge->set($this->event, '0', '50');
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        self::assertSame('10050', wc_format_decimal($this->freshOrder($this->orders()[1])->get_total()));

        $surcharge->set($this->event + 1, '50', null);
        $surcharge->off($this->event);
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        self::assertSame('10000', wc_format_decimal($this->freshOrder($this->orders()[2])->get_total()));
        self::assertSame([], $this->feeLines($this->orders()[2]));
    }

    public function testASurchargeThatIsNotValidIsRefusedAndOneStoredMalformedChargesNothing(): void
    {
        $surcharge = new CardSurcharge();
        $bad = [['100.01', null], ['-1', null], ['1.234', null], ['abc', null], ['5', '-3'], ['5', '1,5']];
        foreach ($bad as [$percent, $fixed]) {
            try {
                $surcharge->set($this->event, $percent, $fixed);
                self::fail($percent . ' ' . $fixed . ' was accepted.');
            } catch (InvalidInput) {
                self::assertNull($surcharge->of($this->event));
            }
        }
        $surcharge->set($this->event, '100', null);
        self::assertSame(['percent' => '100', 'fixed' => null], $surcharge->of($this->event));

        update_option(CardSurcharge::OPTION, [(string) $this->event => ['percent' => 'x']]);
        [$log] = $this->captureLog(function () use ($surcharge): void {
            self::assertNull($surcharge->of($this->event));
        });
        self::assertStringContainsString('malformed', $log);
    }

    public function testAPaidOrderWithAFeeRecordsExactlyTheItemAmountAndTheMailCarriesIt(): void
    {
        $anna = $this->owing('00A0');
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'payment_recorded',
            'version' => 1,
            'enabled' => 1,
            'body_id' => null,
            'layout' => 1,
        ]);
        $this->surcharge('3', '40');
        $this->payUrlFor($this->requestFor([$anna], ['00A0' => '10000']), 'https://example.org/');
        $order = $this->onlyOrder();
        self::assertSame('10340', wc_format_decimal($order->get_total()));

        $this->payOrder($order);

        $lines = $this->moneyLines();
        self::assertCount(1, $lines);
        self::assertSame('-10000.00000000', $lines[0]['amount']);
        self::assertSame('0', $this->balance($anna));
        // The mail is queued for the ledger line, whose amount is the item's: its content is read from that line.
        self::assertSame(
            1,
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_deliveries WHERE mail = 'payment_recorded'"
            )
        );
    }

    /** @return array{\WC_Order, \WC_Order_Item_Fee, \WC_Order_Item} an order of 10000 with a 300 fee, paid */
    private function paidWithAFee(RegistrationKey $anna): array
    {
        $this->surcharge('3', null);
        $this->payUrlFor($this->requestFor([$anna]), 'https://example.org/');
        $order = $this->onlyOrder();
        self::assertSame('10300', wc_format_decimal($order->get_total()));
        $this->payOrder($order);
        $order = $this->freshOrder($order);
        $fees = $this->feeLines($order);
        self::assertCount(1, $fees);

        return [$order, $fees[0], array_values($order->get_items())[0]];
    }

    public function testAFullRefundByLineItemsOfTheItemAndTheFeeRecordsOnlyTheItem(): void
    {
        $anna = $this->owing('00A0');
        [$order, $fee, $item] = $this->paidWithAFee($anna);

        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 10300,
            'line_items' => [
                $item->get_id() => ['qty' => 0, 'refund_total' => 10000, 'refund_tax' => []],
                $fee->get_id() => ['qty' => 0, 'refund_total' => 300, 'refund_tax' => []],
            ],
        ]);

        self::assertSame(['10000.00000000'], array_column($this->refundLines(), 'amount'));
        self::assertSame('10000', $this->balance($anna));
    }

    public function testARefundOfOnlyTheFeeLineRecordsNothing(): void
    {
        $anna = $this->owing('00A0');
        [$order, $fee] = $this->paidWithAFee($anna);

        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => 300,
            'line_items' => [$fee->get_id() => ['qty' => 0, 'refund_total' => 300, 'refund_tax' => []]],
        ]);

        self::assertSame([], $this->refundLines());
        self::assertSame('0', $this->balance($anna));
    }

    public function testAnAmountOnlyFullRefundOfTheItemAndTheFeeRecordsOnlyTheItem(): void
    {
        $anna = $this->owing('00A0');
        [$order] = $this->paidWithAFee($anna);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10300]);

        self::assertSame(['10000.00000000'], array_column($this->refundLines(), 'amount'));
        self::assertSame('10000', $this->balance($anna));
    }

    public function testAPartialAmountOnlyRefundOfAnOrderWithAFeeGoesToTheItem(): void
    {
        $anna = $this->owing('00A0');
        [$order] = $this->paidWithAFee($anna);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 2500]);

        self::assertSame(['2500.00000000'], array_column($this->refundLines(), 'amount'));
    }

    public function testASeparateFeeRefundAfterTheItemWasRefundedByAmountRecordsNothingMore(): void
    {
        $anna = $this->owing('00A0');
        [$order] = $this->paidWithAFee($anna);

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 10000]);
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 300]);

        self::assertSame(['10000.00000000'], array_column($this->refundLines(), 'amount'));
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
