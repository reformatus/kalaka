# Support

Support is the organization directory, the congregation picker and the
support offers: one feature plugin, `kalaka-support` (module `support`), an
extension that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
So far it holds the directory.

## Sections it implements

- money.md: [Sponsor and church support](../../../../docs/architecture/money.md#sponsor-and-church-support)
  (the directory).
- foundation.md: [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (a congregation's ancestors are computed),
  [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants)
  (a tree lookup is expanded in SQL).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Directory nodes are never deleted; deactivating sets the active flag and
  keeps the node, so a stored node ID always resolves.
- Nodes change only through `kalaka/save-directory-node`, keyed by the
  external ID; no parent chain contains a cycle.
- Only `parent_id` is stored about the tree; ancestors and descendants are
  computed in SQL when read.
- Every public route is under `kalaka-support/v1`, registered in
  `Module::boot()` through `Boundary::route()`, and calls
  `LoadState::requireOpen()`, which refuses with `SiteClosed` while the site
  is closed.
- The public search lists only live nodes (active, under active ancestors)
  and never their external IDs or aliases.
- Search predicates use `utf8mb4_unicode_520_ci`; stored text uses
  `utf8mb4_hungarian_ci`, except `external_id`, which is `ascii_bin`.
- A node's external ID is its import row's `id`, or else its parent's
  external ID + `/` + the slug of its name; it is never suffixed, and a clash
  fails the rows involved.
- No directory data is shipped; tests use synthetic nodes only.
