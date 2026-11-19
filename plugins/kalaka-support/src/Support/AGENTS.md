# Support

Support is the organization directory, the congregation picker and the
support offers: one feature plugin, `kalaka-support` (module `support`), an
extension that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
So far it holds the directory and the congregation picker.

## Sections it implements

- money.md: [Sponsor and church support](../../../../docs/architecture/money.md#sponsor-and-church-support)
  (the directory and the congregation field type).
- events-and-forms.md: [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (an extension's field type).
- foundation.md: [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (the tree's ancestors are computed; a congregation answer keeps its
  snapshot),
  [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants)
  (a tree lookup is expanded in SQL).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership),
  the field-type point of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Directory nodes are never deleted; deactivating sets the active flag and
  keeps the node, so a stored node ID always resolves.
- Nodes change only through `kalaka/save-directory-node`, keyed by the
  external ID; no parent chain contains a cycle.
- Only `parent_id` is stored about the tree; ancestors and descendants are
  computed in SQL when read. The one copy of a path kept elsewhere is a
  congregation answer's snapshot (below).
- Every public route is under `kalaka-support/v1`, registered in
  `Module::boot()` through `Boundary::route()`, and calls
  `LoadState::requireOpen()`, which refuses with `SiteClosed` while the site
  is closed.
- The public search lists only live nodes (active, under active ancestors)
  and never their external IDs or aliases. A search within a node walks its
  subtree in the same statements as the live set, never through a list of
  ids.
- Search predicates use `utf8mb4_unicode_520_ci`; stored text uses
  `utf8mb4_hungarian_ci`, except `external_id`, which is `ascii_bin`.
- A node's external ID is its import row's `id`, or else its parent's
  external ID + `/` + the slug of its name; it is never suffixed, and a clash
  fails the rows involved.
- No directory data is shipped; tests use synthetic nodes only.
- The congregation field writes no table of its own: its answer is core's
  field value, JSON of the node's id and a snapshot of its path (each node's
  external ID and type, root first, ending with the leaf), taken when the
  answer is checked. A re-import never changes a stored answer. A new answer
  is a live terminal leaf (a node with no children at all, `NodeList::LEAF`),
  checked against the directory when the answer is checked.
- Conditions read the path's external IDs, never database ids: a level is
  asked with `contains`, and `equals` is refused (`PathFieldType`). The
  value's text is the leaf's current name. The public route never shows
  external IDs.
- The field's editor is built from core's field-editor pieces
  (`window.kalakaFieldEditor`; `block.json` names `kalaka-form-editor-script`
  first): the one keeper of keys, so a copy gets a fresh key, the settings,
  the participant-field and key panels. It copies none of them.
