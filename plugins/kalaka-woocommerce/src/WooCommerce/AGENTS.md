# WooCommerce

WooCommerce is the WooCommerce integration: one extension plugin,
`kalaka-woocommerce` (module `woocommerce`), that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).

## Sections it implements

- modules-and-extensions.md: [Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)
  (the WooCommerce integration is our own extension plugin),
  [Extensions in view](../../../../docs/architecture/modules-and-extensions.md#extensions-in-view),
  [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Failure isolation](../../../../docs/architecture/modules-and-extensions.md#failure-isolation).
- money.md: [Payments](../../../../docs/architecture/money.md#payments) (the WooCommerce extension implements
  core's payments port: orders through WooCommerce's own API, one hidden product line per registration line,
  paid and refunded events written exactly once, keyed by order and refund).
- foundation.md: [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants)
  (a grant holder reaches the admin side).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- WooCommerce's `woocommerce_prevent_admin_access` filter returns false when
  `Kalaka\Admin\Api\Access::mayReachAdmin()` says the current user reaches the
  admin side (full access or any grant); otherwise the incoming value is
  returned unchanged. Nobody else gains anything.
- WooCommerce's `woocommerce_disable_admin_bar` filter (which switches
  `wc_disable_admin_bar()`, hiding the front-end admin bar) is answered the
  same way, so that user keeps the admin bar on the front end. The login
  redirect to My Account is left alone.
- The filters are guarded for failure isolation: core's `Access` missing, or a
  throw inside, returns the incoming value, so WooCommerce's own decision
  stands and the admin stays up. A throw is logged through
  `Boundary::report()` on each failing request.
- One login and one password screen, WordPress's own (`LoginRedirects`, always on, no setting; owner ruling
  2026-11-26). On `template_redirect` (priority 20, after WooCommerce's own handlers) a logged-out `GET` or `HEAD`
  of a My Account page goes to `wp_login_url()` returning to the requested address, and so does the pay page of an
  order with a `customer_id` above 0 when the request carries its right order key (a guest order, `customer_id` 0,
  and a wrong key are never moved). A logged-out visit to My Account's lost-password endpoint goes to
  `wp_lostpassword_url()`, except WooCommerce's own reset flow (`key`, `show-reset-form`, `reset-link-sent` in the
  query, which WooCommerce's mails and its own redirect produce), which is left alone. `wc_lostpassword_url()` is
  taken off the `lostpassword_url` filter in `boot()`, so `wp_lostpassword_url()` is core's address. A logged-in
  visitor, a form post, AJAX, cron and REST are never redirected. A throw is reported through `Boundary::report()`
  and the page renders as WooCommerce would.
- The plugin stores only order and item meta, written and read through WooCommerce's own order API
  (`wc_get_order()`, `wc_get_orders()`, the CRUD methods; never post meta or SQL, so both order-storage modes
  work), and two options: `kalaka_woocommerce_product_id`, the ID of the one hidden product (private, and not purchasable by any
  filter, so only an order of this plugin holds it), and `kalaka_woocommerce_card_surcharge`, the per-event card
  surcharge. It has no table.
- A payment request is one order, with the billing email and, when a `BillingSource` gives any, the payer's name
  (whole, in the first-name field), country, postcode, city and address (only the non-empty ones; the default
  source `NoBilling` gives none, so today an order carries the email only), and the customer IP
  address and user agent WooCommerce stores on a new order are cleared; the order's currency is the request's. The
  order is the owner account's (`customer_id`) only when every line's registration has that same owner account
  and the paying visitor is logged in as that user (`OrderOwner`: WooCommerce's pay page lets only the customer
  pay a customer order, so a logged-out registrant or another user would be refused); in every other case, lines
  of several owners included, it is a guest order, which anyone holding its key can pay. The visitor is read
  from the logged-in cookie (`wp_validate_auth_cookie()`, read-only: the card start is a REST post without a
  `wp_rest` nonce, which REST reads as logged out), and decides nothing but that customer. The order has one item of the
  hidden product per request line: quantity 1, subtotal and total the amount, name the line's label, item meta
  `_kalaka_registration` (`12:47K3`, composed and parsed only through `Payments\Api\RegistrationRef`) and
  `_kalaka_payer`. The order carries `_kalaka`, one `_kalaka_registration` row per line, `_kalaka_return_url`
  and, once recorded, `_kalaka_recorded`. No tax is added.
- A new start never reuses an order. Only a never-paid order (pending, failed, a declined card) is cancelled,
  re-read first; a paid order is never touched. `payUrl()` creates one new order and then cancels the request's
  registrations' never-paid orders (the new one excluded), under the start's lock in core, so at most one unpaid
  order exists. Any other open
  order refuses the start: `latestOf()` looks at every order of the registration before the newest, and answers
  `processing` for an order on hold or in any status Kaláka does not handle (the closed ones, `cancelled`,
  `refunded` and `trash`, and the never-paid ones are the only statuses it passes over, so an unforeseen status
  blocks), and `paid` for a paid order whose payment is not recorded yet; core refuses the start as
  `CardPaymentInFlight` before anything is cancelled or created, and staff resolve the order in WooCommerce. A
  paid order is `paid` only until its payment is recorded; then it blocks nothing and the money table shows the
  movement.
- The card surcharge is a per-event setting of this plugin, the option `kalaka_woocommerce_card_surcharge` (a map of
  event ID to `{percent, fixed?}`, set only by `wp kalaka card-surcharge <event> [--percent=] [--fixed=] [--off]`;
  there is no screen), and a WooCommerce fee line only: a new order of an event with a surcharge gets one taxless fee
  line (`Kártyás fizetés díja`) of `amount × percent / 100 + fixed` rounded up to the payable unit, and its total is
  the item plus the fee. It is never ledger money: a payment records the Kaláka items only, and a refund never gives
  a fee line to an item. A malformed stored value is read as no surcharge and reported.
- A paid order is recorded by one function, `PaymentReporter::recordPaidIfPaid()`, from
  `woocommerce_payment_complete` and the move to processing or completed: it acts only on one of its own orders
  that has a paid date, whatever its status is now (a refunded order was paid), writes one line per item for what the item actually charged, and then
  sets `_kalaka_recorded`. A later move to pending, failed, cancelled or trash writes nothing and is reported to
  the error log; only refund objects reverse money.
- A refund (`woocommerce_order_refunded`) records its item amounts. One that names no item goes to the one item
  of a one-item order, and on an order of several is split in item order up to what each item has left; core
  writes that on the audit entry. A refund that names a fee line (the surcharge), shipping or any other line that is
  not a Kaláka item gives none of it to an item. A refund that names items and a larger amount gives the excess to
  the items in item order, each up to what it has left, and what no item has room for (the surcharge) is not
  recorded. A refund is recorded whatever the order's status, and records the order's payment first when that is not
  recorded yet.
- Nothing here throws into WooCommerce's payment flow: a failure is reported through `Boundary::report()`, and
  the periodic reconcile step records an order paid in the last 30 days that no report recorded, and every
  refund made in the last 30 days again (idempotent by refund and item), on the runner's clock (one still
  unrecorded an hour after payment is reported to the log on every run).
- `uninstall.php` deletes the options and leaves the hidden product, the orders and their meta: they belong to
  WooCommerce's records of money.
