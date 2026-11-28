# WooCommerce

The WooCommerce integration (`kalaka-woocommerce`, module `woocommerce`): on a
WooCommerce site a grant holder reaches Kaláka's admin side.

`Module.php` registers `AdminAccessGate` on WooCommerce's
`woocommerce_prevent_admin_access` and `woocommerce_disable_admin_bar` filters
in `boot()`.

`AdminAccessGate` runs `Kalaka\Admin\Api\Access::mayReachAdmin()` for the
current user: full access or at least one grant returns false, so WooCommerce's
`WC_Admin::prevent_admin_access` does not send the user to My Account. Everyone
else keeps WooCommerce's own decision. The same check keeps the front-end admin
bar for that user. If core's API is absent or anything
throws, the incoming value is returned and a throw is reported through
`Foundation\Api\Entry\Boundary`.

`LoginRedirects` (`template_redirect`, priority 20) keeps one login and one password screen, WordPress's own: a
logged-out visit to a My Account page, or to the pay page of an order an account owns, goes to `wp_login_url()` and
back to the requested address; a logged-out visit to My Account's lost-password endpoint (not WooCommerce's own reset
flow) goes to core's lost-password screen; `boot()` removes WooCommerce's `lostpassword_url` override
(`wc_lostpassword_url()`), so `wp_lostpassword_url()` is `wp-login.php?action=lostpassword`. Always on. Guest orders,
logged-in visitors, form posts, AJAX, cron and REST are not redirected.

Tests: `tests/integration/` (the login redirects; the filter on a real WooCommerce install, a grant
holder through WooCommerce's own `prevent_admin_access()`, a plain customer
that WooCommerce really redirects, full access, an administrator and the
missing-API guard).

Entry points: `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`, `template_redirect` (priority 20), and the removal of WooCommerce's `lostpassword_url` filter.
