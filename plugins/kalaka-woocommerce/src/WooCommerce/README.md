# WooCommerce

The WooCommerce integration (`kalaka-woocommerce`, module `woocommerce`): on a
WooCommerce site a grant holder reaches Kaláka's admin side, and registrants pay by card through WooCommerce
orders, as core's payments provider.

`Module.php` registers `AdminAccessGate` on WooCommerce's
`woocommerce_prevent_admin_access` and `woocommerce_disable_admin_bar` filters
in `boot()`, and the card payment's hooks (`PaymentReporter`, `ReturnAddress`,
`HiddenProduct::purchasable()`; see the entry points below); it contributes the
provider and the `ReconcileStep`.

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

Card payment (`Payments\Api\PaymentProvider`): `WooProvider` (extends `AbstractPaymentProvider`; each operation takes one `PaymentProviderInput`; `payUrl()` creates an order, then cancels the registrations' other never-paid orders (the owner account's when it is the one paying, else a guest order: `OrderOwner`; the billing details of a `BillingSource`, none by default: `BillingDetails`, `BillingSource`, `NoBilling`) with one item of the
hidden product (`HiddenProduct`) per line, plus one fee line when the event has a card surcharge (`CardSurcharge`, the option `kalaka_woocommerce_card_surcharge`, set by `wp kalaka card-surcharge` in `Cli/CardSurchargeCommand`);
`latestOf()` answers `processing` for any order open in a status Kaláka does not handle, so core refuses the start; `accepts()`), the hidden product is private and not purchasable (`HiddenProduct::purchasable()`), `OrderFinder` (`wc_get_orders()` by `meta_key`, valid in both storage modes), `KalakaOrder`
and `KalakaItem` (an order as the plugin reads it), `OrderMeta` (the meta names and the option), `PaymentReporter`
(`recordPaidIfPaid()`, `recordRefund()`, `statusChanged()`, `trashed()`: what WooCommerce tells core), `RefundSplit`
(which items a refund gave back; a fee line never goes to an item), `ReconcileStep` (the periodic step for a paid order and a refund no report recorded) and
`ReturnAddress` (`woocommerce_get_return_url`: back to the page the registrant came from).

Tests: `tests/integration/` (the login redirects; the filter on a real WooCommerce install, a grant
holder through WooCommerce's own `prevent_admin_access()`, a plain customer
that WooCommerce really redirects, full access, an administrator and the
missing-API guard; the card payment on real `WC_Order`s in both order-storage modes, with a test-only stand-in gateway).

Entry points: `woocommerce_prevent_admin_access`, `woocommerce_disable_admin_bar`, `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, `woocommerce_order_refunded`, `woocommerce_order_status_changed`, `woocommerce_trash_order`, `woocommerce_get_return_url`, `woocommerce_is_purchasable`, `template_redirect` (priority 20), and the removal of WooCommerce's `lostpassword_url` filter; the CLI command `wp kalaka card-surcharge`; the periodic step `woocommerce_reconcile`.
