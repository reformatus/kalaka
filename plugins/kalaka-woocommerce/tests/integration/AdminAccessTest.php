<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce;

use Kalaka\Admin\Api\Access;
use Kalaka\Tests\Integration\Admin\GrantTestCase;
use Kalaka\WooCommerce\AdminAccessGate;

/**
 * S51 part 1: on a WooCommerce site a user who may reach Kaláka's admin side (full access or any
 * grant) is not redirected away from wp-admin by WooCommerce; nobody else gains anything.
 */
final class AdminAccessTest extends GrantTestCase
{
    private const FILTER = 'woocommerce_prevent_admin_access';

    /** The instance WordPress gets from WooCommerce's admin class file; required once for the process. */
    private static ?\WC_Admin $woocommerceAdmin = null;

    private int $event;

    private int $grantee;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        Access::forget();
        $this->event = $this->createEvent();
        $this->grantee = self::factory()->user->create(['role' => 'kalaka_grantee']);
        $this->saveGrant($this->event, $this->grantee);
        Access::forget();

        // WordPress's own admin request has an admin file as SCRIPT_FILENAME; WooCommerce skips
        // admin-post.php and admin-ajax.php. In tests-cli no admin request runs, so name one.
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/wp-admin/index.php';
        self::woocommerceAdmin();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Access::forget();
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** A grant holder gets into wp-admin through WooCommerce's own method, not to My Account. */
    public function testAGrantHolderIsNotRedirectedFromTheAdmin(): void
    {
        self::assertTrue(class_exists('WooCommerce'), 'WooCommerce must really be loaded in this test.');
        self::assertNotFalse(has_filter(self::FILTER), 'The extension must register its filter.');

        wp_set_current_user($this->grantee);
        Access::forget();

        self::assertTrue(Access::mayReachAdmin($this->grantee));
        self::assertFalse(current_user_can('edit_posts'));
        self::assertFalse(current_user_can('manage_woocommerce'));
        self::assertTrue($this->woocommercePrevents(), 'Without the filter WooCommerce would redirect.');
        self::assertFalse(apply_filters(self::FILTER, $this->woocommercePrevents()));

        // The same path an admin page request takes: WooCommerce's own method, which would
        // wp_safe_redirect() and exit for a user it keeps out.
        $redirectedTo = null;
        $capture = static function (string $location) use (&$redirectedTo): bool {
            $redirectedTo = $location;

            return false;
        };
        add_filter('wp_redirect', $capture);
        self::woocommerceAdmin()->prevent_admin_access();
        remove_filter('wp_redirect', $capture);

        self::assertNull($redirectedTo);
    }

    /** A plain customer without a grant is still sent to My Account. */
    public function testAPlainCustomerIsStillRedirected(): void
    {
        $customer = self::factory()->user->create(['role' => 'customer']);
        wp_set_current_user($customer);
        Access::forget();

        self::assertFalse(Access::mayReachAdmin($customer));
        self::assertTrue($this->woocommercePrevents());
        self::assertTrue(apply_filters(self::FILTER, $this->woocommercePrevents()));
    }

    /** A full-access holder has neither WooCommerce capability but still passes. */
    public function testAFullAccessHolderPasses(): void
    {
        wp_set_current_user($this->admin->userId);
        Access::forget();

        self::assertTrue(Access::mayReachAdmin($this->admin->userId));
        self::assertTrue($this->woocommercePrevents());
        self::assertFalse(apply_filters(self::FILTER, $this->woocommercePrevents()));
    }

    /** An administrator is unaffected: WooCommerce never prevented them. */
    public function testAnAdministratorIsUnaffected(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        Access::forget();

        self::assertTrue(current_user_can('edit_posts'));
        self::assertFalse($this->woocommercePrevents());
        self::assertFalse(apply_filters(self::FILTER, $this->woocommercePrevents()));
    }

    /** Core absent or inactive: the gate passes the incoming value through, bool or not. */
    public function testTheGatePassesTheIncomingValueThroughWithoutTheCoreApi(): void
    {
        $gate = new AdminAccessGate('Kalaka\\Missing\\Access');

        self::assertTrue($gate->prevent(true));
        self::assertFalse($gate->prevent(false));
        self::assertSame('valami', $gate->prevent('valami'));
    }

    /** A throw inside the check keeps WooCommerce's own answer instead of taking the admin down. */
    public function testAThrowInsideTheCheckKeepsWooCommercesAnswer(): void
    {
        wp_set_current_user($this->grantee);
        $gate = new AdminAccessGate(ThrowingAccess::class);

        self::assertTrue($gate->prevent(true));
        self::assertFalse($gate->prevent(false));
    }

    /** WooCommerce's own decision before the filter, as `WC_Admin::prevent_admin_access()` computes it. */
    private function woocommercePrevents(): bool
    {
        if (!apply_filters('woocommerce_disable_admin_bar', true)) {
            return false;
        }
        if (!isset($_SERVER['SCRIPT_FILENAME'])) {
            return false;
        }
        $file = basename(sanitize_text_field(wp_unslash((string) $_SERVER['SCRIPT_FILENAME'])));
        if (in_array($file, ['admin-post.php', 'admin-ajax.php'], true)) {
            return false;
        }
        foreach (['edit_posts', 'manage_woocommerce', 'view_admin_dashboard'] as $capability) {
            if (current_user_can($capability)) {
                return false;
            }
        }

        return true;
    }

    private static function woocommerceAdmin(): \WC_Admin
    {
        if (self::$woocommerceAdmin === null) {
            if (!class_exists('WC_Admin')) {
                $admin = require WC_ABSPATH . 'includes/admin/class-wc-admin.php';
                self::assertInstanceOf(\WC_Admin::class, $admin);
                self::$woocommerceAdmin = $admin;
            } else {
                self::$woocommerceAdmin = new \WC_Admin();
            }
        }

        return self::$woocommerceAdmin;
    }
}
