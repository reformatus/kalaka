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

Tests: `tests/integration/` (the filter on a real WooCommerce install, a grant
holder through WooCommerce's own `prevent_admin_access()`, a plain customer
that WooCommerce really redirects, full access, an administrator and the
missing-API guard).

Entry points: `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`.
