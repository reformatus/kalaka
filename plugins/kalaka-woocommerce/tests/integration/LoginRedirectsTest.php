<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce;

use Kalaka\WooCommerce\LoginRedirects;
use WP_UnitTestCase;

/** One login and one password screen: a logged-out visit to My Account or an account order's pay page goes to wp-login.php. */
final class LoginRedirectsTest extends WP_UnitTestCase
{
    private int $account;

    private int $checkout;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->account = self::factory()->post->create(
            ['post_type' => 'page', 'post_content' => '[woocommerce_my_account]']
        );
        $this->checkout = self::factory()->post->create(
            ['post_type' => 'page', 'post_content' => '[woocommerce_checkout]']
        );
        update_option('woocommerce_myaccount_page_id', $this->account);
        update_option('woocommerce_checkout_page_id', $this->checkout);
        wp_set_current_user(0);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);
        unset($_GET['key'], $_GET['show-reset-form'], $_SERVER['REQUEST_METHOD']);

        parent::tear_down();
    }

    public function testTheModuleHooksTheRedirectAfterWooCommercesOwnHandlers(): void
    {
        self::assertInstanceOf(LoginRedirects::class, $this->hooked());
        self::assertFalse(has_filter('lostpassword_url', 'wc_lostpassword_url'));
    }

    public function testALoggedOutVisitorOnMyAccountGoesToTheLoginAndBack(): void
    {
        $url = home_url('/?page_id=' . $this->account);

        $location = $this->redirectOf($url);

        self::assertNotNull($location);
        self::assertStringStartsWith(wp_login_url(), $location);
        self::assertSame($url, $this->returnOf($location));
    }

    public function testAnEndpointOfMyAccountGoesTheSameWay(): void
    {
        $url = home_url('/?page_id=' . $this->account . '&orders');

        $location = $this->redirectOf($url);

        self::assertNotNull($location);
        self::assertSame($url, $this->returnOf($location));
    }

    public function testALoggedInVisitorOnMyAccountIsNotRedirected(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'customer']));

        self::assertNull($this->redirectOf(home_url('/?page_id=' . $this->account)));
    }

    public function testAFormPostOnMyAccountIsNotRedirected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        self::assertNull($this->redirectOf(home_url('/?page_id=' . $this->account)));
    }

    public function testAnotherPageIsNotRedirected(): void
    {
        $other = self::factory()->post->create(['post_type' => 'page']);

        self::assertNull($this->redirectOf(home_url('/?page_id=' . $other)));
    }

    public function testTheLoginReturnsToWhereTheVisitorWasAsked(): void
    {
        // The address wp-login.php would send the user back to is accepted by wp_safe_redirect().
        $url = home_url('/?page_id=' . $this->account . '&orders');

        self::assertSame($url, wp_validate_redirect($url));
    }

    public function testThePayPageOfAnAccountOrderGoesToTheLoginAndBack(): void
    {
        $order = $this->order(self::factory()->user->create(['role' => 'customer']));
        $url = $this->payUrl($order);

        $location = $this->redirectOf($url);

        self::assertNotNull($location);
        self::assertStringStartsWith(wp_login_url(), $location);
        self::assertSame($url, $this->returnOf($location));
    }

    public function testThePayPageOfAGuestOrderIsNotRedirected(): void
    {
        self::assertNull($this->redirectOf($this->payUrl($this->order(0))));
    }

    public function testThePayPageOfAnAccountOrderWithAWrongKeyIsNotRedirected(): void
    {
        $order = $this->order(self::factory()->user->create(['role' => 'customer']));

        self::assertNull($this->redirectOf($this->payUrl($order, 'wc_order_wrong')));
    }

    public function testALoggedInVisitorOnThePayPageIsNotRedirected(): void
    {
        $customer = self::factory()->user->create(['role' => 'customer']);
        $order = $this->order($customer);
        wp_set_current_user($customer);

        self::assertNull($this->redirectOf($this->payUrl($order)));
    }

    public function testTheLostPasswordUrlIsCoresOwn(): void
    {
        self::assertSame(site_url('wp-login.php?action=lostpassword', 'login'), wp_lostpassword_url());
        self::assertSame(
            site_url('wp-login.php?action=lostpassword&redirect_to=' . rawurlencode('http://example.org/'), 'login'),
            wp_lostpassword_url('http://example.org/')
        );
    }

    public function testTheLostPasswordEndpointGoesToCoresScreenWhenLoggedOut(): void
    {
        self::assertSame(
            wp_lostpassword_url(),
            $this->redirectOf(home_url('/?page_id=' . $this->account . '&lost-password'))
        );
    }

    public function testTheLostPasswordEndpointOfWooCommercesResetFlowIsLeftAlone(): void
    {
        $_GET['show-reset-form'] = 'true';

        $url = home_url('/?page_id=' . $this->account . '&lost-password&show-reset-form=true');
        self::assertNull($this->redirectOf($url));
    }

    public function testTheLostPasswordEndpointIsLeftAloneForALoggedInUser(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'customer']));

        self::assertNull($this->redirectOf(home_url('/?page_id=' . $this->account . '&lost-password')));
    }

    private function hooked(): LoginRedirects
    {
        global $wp_filter;
        foreach ($wp_filter['template_redirect']->callbacks[20] ?? [] as $callback) {
            if (is_array($callback['function']) && $callback['function'][0] instanceof LoginRedirects) {
                return $callback['function'][0];
            }
        }
        self::fail('The module must hook LoginRedirects on template_redirect at priority 20.');
    }

    /** What `template_redirect` does for a logged-out request of this URL: the redirect location, or null. */
    private function redirectOf(string $url): ?string
    {
        $this->go_to($url);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the test sets the request it simulates.
        $_GET = array_merge($_GET, (array) wp_parse_args((string) wp_parse_url($url, PHP_URL_QUERY)));

        $location = null;
        $marker = new \RuntimeException('redirected');
        $capture = static function (string $to) use (&$location, $marker): string {
            $location = $to;
            throw $marker;
        };
        add_filter('wp_redirect', $capture);
        try {
            $this->hooked()->redirect();
        } catch (\RuntimeException $e) {
            if ($e !== $marker) {
                throw $e;
            }
        } finally {
            remove_filter('wp_redirect', $capture);
        }

        return $location;
    }

    private function returnOf(string $location): string
    {
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);

        return (string) ($query['redirect_to'] ?? '');
    }

    private function order(int $customer): \WC_Order
    {
        $order = wc_create_order(['customer_id' => $customer, 'status' => 'pending']);
        self::assertInstanceOf(\WC_Order::class, $order);
        $order->save();

        return $order;
    }

    private function payUrl(\WC_Order $order, ?string $key = null): string
    {
        $key ??= $order->get_order_key();

        return home_url('/?page_id=' . $this->checkout . '&order-pay=' . $order->get_id() . '&key=' . $key);
    }
}
