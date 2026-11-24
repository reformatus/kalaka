# WooCommerce

The WooCommerce integration (`kalaka-woocommerce`, module `woocommerce`): on a
WooCommerce site a grant holder reaches Kaláka's admin side, and registrants pay by card through WooCommerce
orders, as core's payments provider.

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

Card payment (`Payments\Api\PaymentProvider`): `WooProvider` (`payUrl()` creates a guest order with one item of the
hidden product (`HiddenProduct`) per line, or reuses an unpaid one with equal lines and cancels the other unpaid ones;
`latestOf()`; `accepts()`), the hidden product is private and not purchasable (`HiddenProduct::purchasable()`), `OrderFinder` (`wc_get_orders()` by `meta_key`, valid in both storage modes), `KalakaOrder`
and `KalakaItem` (an order as the plugin reads it), `OrderMeta` (the meta names and the option), `PaymentReporter`
(`recordPaidIfPaid()`, `recordRefund()`, `statusChanged()`, `trashed()`: what WooCommerce tells core), `RefundSplit`
(which items a refund gave back), `ReconcileStep` (the periodic step for a paid order and a refund no report recorded) and
`ReturnAddress` (`woocommerce_get_return_url`: back to the page the registrant came from).

Tests: `tests/integration/` (the filter on a real WooCommerce install, a grant
holder through WooCommerce's own `prevent_admin_access()`, a plain customer
that WooCommerce really redirects, full access, an administrator and the
missing-API guard; the card payment on real `WC_Order`s in both order-storage modes, with a test-only stand-in gateway) (the filter on a real WooCommerce install, a grant
holder through WooCommerce's own `prevent_admin_access()`, a plain customer
that WooCommerce really redirects, full access, an administrator and the
missing-API guard).

Entry points: `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`, `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_order_refunded`, `woocommerce_order_status_changed`, `woocommerce_trash_order`, `woocommerce_get_return_url`; the periodic step `woocommerce_reconcile`.
