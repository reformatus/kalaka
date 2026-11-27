<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * One login screen and one password screen: WordPress's own `wp-login.php`.
 *
 * A logged-out visit to a My Account page, or to the pay page of an order an account owns, goes to
 * `wp_login_url()` with a return to the requested address; a logged-out visit to My Account's
 * lost-password endpoint goes to core's lost-password screen. WooCommerce's own override of
 * `lostpassword_url` (`wc_lostpassword_url()`) is removed, so `wp_lostpassword_url()` answers core's
 * address. Always on; there is no setting.
 *
 * Failure isolation: these hooks run on the front end outside any transaction. Anything that throws
 * is reported and the page renders as WooCommerce would have rendered it.
 */
final class LoginRedirects
{
    /** Query arguments of WooCommerce's own reset flow, which a visitor lands on from a WooCommerce mail. */
    private const RESET_FLOW = ['key', 'show-reset-form', 'reset-link-sent'];

    /** Takes the lost-password override off the filter; core's address stands. */
    public function removeLostPasswordOverride(): void
    {
        remove_filter('lostpassword_url', 'wc_lostpassword_url', 10);
    }

    /** @internal the `template_redirect` action, after WooCommerce's own handlers (priority 10) */
    public function redirect(): void
    {
        $target = null;
        try {
            $target = $this->targetOf($this->request());
        } catch (\Throwable $e) {
            try {
                if (class_exists(Boundary::class)) {
                    Boundary::report('sending a logged-out visitor to the login', $e);
                }
            } catch (\Throwable) {
                // Failure isolation: even the report must not take the page down.
            }
        }

        if ($target !== null) {
            wp_safe_redirect($target);
            exit;
        }
    }

    /** Where the logged-out request goes, or null when it stays where it is. */
    private function targetOf(string $requested): ?string
    {
        if (is_user_logged_in() || !$this->isPageRequest()) {
            return null;
        }

        if (is_lost_password_page()) {
            foreach (self::RESET_FLOW as $argument) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence only
                if (isset($_GET[$argument])) {
                    return null;
                }
            }

            return wp_lostpassword_url();
        }

        if (is_account_page() || $this->isPayPageOfAnAccountOrder()) {
            return wp_login_url($requested);
        }

        return null;
    }

    /** A plain page view: not a form post, AJAX, cron or REST (which do not reach `template_redirect` anyway). */
    private function isPageRequest(): bool
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))
            : 'GET';

        return in_array($method, ['GET', 'HEAD'], true)
            && !wp_doing_ajax()
            && !wp_doing_cron()
            && !(defined('REST_REQUEST') && REST_REQUEST);
    }

    /** The pay page of an order that belongs to an account, with its key; a guest order is not ours to move. */
    private function isPayPageOfAnAccountOrder(): bool
    {
        if (!is_checkout_pay_page()) {
            return false;
        }

        $order = wc_get_order(absint(get_query_var('order-pay')));
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with the order's key only
        $key = isset($_GET['key']) && is_string($_GET['key']) ? wp_unslash($_GET['key']) : '';
        // phpcs:enable

        // Only with the right key, so the redirect tells a stranger nothing about an order number.
        return $order instanceof \WC_Order
            && $order->get_customer_id() > 0
            && $key !== ''
            && hash_equals($order->get_order_key(), $key);
    }

    /** The address asked for, on this site's own scheme and host. */
    private function request(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only handed to wp_safe_redirect() and the login's own validation
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        $home = (array) wp_parse_url(home_url());
        $port = isset($home['port']) ? ':' . $home['port'] : '';

        return ($home['scheme'] ?? 'http') . '://' . ($home['host'] ?? '') . $port . $uri;
    }
}
