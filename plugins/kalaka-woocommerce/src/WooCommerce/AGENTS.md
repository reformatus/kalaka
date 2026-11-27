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
- The plugin stores nothing of its own. Payments are not part of this slice.
