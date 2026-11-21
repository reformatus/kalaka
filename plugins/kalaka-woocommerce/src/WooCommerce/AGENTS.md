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
- The plugin stores nothing of its own. Payments are not part of this slice.
