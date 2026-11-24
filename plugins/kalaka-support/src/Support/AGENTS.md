# Support

Support is the organization directory, the congregation picker and the
support offers: one feature plugin, `kalaka-support` (module `support`), an
extension that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
It holds the directory, the congregation picker and the support offers.

## Sections it implements

- money.md: [Sponsor and church support](../../../../docs/architecture/money.md#sponsor-and-church-support)
  (the directory, the congregation field type and the offers),
  [Quota places](../../../../docs/architecture/money.md#quota-places) (an offer's seats).
- events-and-forms.md: [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (an extension's field type).
- foundation.md: [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (the tree's ancestors are computed; a congregation answer keeps its
  snapshot),
  [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants)
  (a congregation level matches the answer's stored path snapshot).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership),
  the field-type point of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee-component, request-source and seat-claim-source points).

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

## Offers (`Offers/`)

- An event's offers are rows of `kalaka_support_offers`, written only through `kalaka/set-support-offers`, which
  the money settings apply runs (`Api\Offers::propose()`, the component `support`'s settings document
  `{"offers": [{key, label, node, amount, request, limit}]}`); the apply then re-evaluates every open
  registration. The save refuses a duplicate or malformed key (`[a-z][a-z0-9_]*`, at most 40 characters: the key
  is also an item key, `support:<key>`), an unknown node, a label outside 1 to 191 characters, an amount that is
  not positive, a limit under 0 (a limit of 0 is a full offer), more than 50 offers and any unknown property. It
  also refuses to remove an offer, or to flip its "must be requested" flag, while an open registration has
  selected it (`OfferChangeGuard`, through the shared `Registrations::openHolds()`): in the preview (`Api\Offers::propose()`) and before any save of an
  apply, so an apply never half-saves; the use case checks again as a guard.
- The save declares the offer's fields in the same transaction, through core's `declareExtensionField()` as
  declarer `kalaka-support`, money relevant: the selection, a consent field whose key is the offer's key
  (form-writable, titled with the offer's label), and for an offer that must be requested its answer
  `<key>_answer` (a choice of `approved` and `refused`, not form-writable, written only by `OfferRequests`).
  The keys are judged by core's `ParticipantFields::checkDeclarable()` (a form's field of the type is adopted;
  another holder refuses with `RuleViolation`), never by a check of this module. The form links to them by key; who sees an offer, and
  whether it stacks with another, are conditions on the form, never here. A removed offer's fields stay
  declared: core has no way to retire a field.
- An offer applies, in the settings' order, when its consent holds `1`, it needs no request or its answer is
  `approved`, and it has no limit or the registration holds its seat. Each applying offer is one participant-side
  `support:<key>` line, named by the offer's own label (`NamedFeeComponent`; a removed offer's line reads as
  „Díj”), negative, and all together are capped at the base price (the tier stage), the earlier
  offers first; an offer awaiting its answer, refused, or without its seat gives no line. The component reads
  facts, its own settings and the seats; never a charge line or an answer row.
- An offer's seats are `Seats` under the pool `offer.<key>`, at the quota lock level (`OfferSeatPool`, the row
  of the offer), for every offer, limited or not (a chosen offer always holds its seat row): `OfferClaims` gives
  a submit's claims and `pools()` the pools of the form variable; the answer act frees and takes the seat through
  core's `SeatOfferSeats` (over `Seats::retake()`, like admission and restore). This module takes and frees a seat nowhere else, and writes nothing of
  `kalaka_seats`.
- A selection is written only by a submit (which takes the seat) and by the answer act. An additional form that
  would write an offer key is refused (CF4); staff placing into an offer is not built (N104).
- `OfferRequests` is the request source of the offers that must be requested: a selected offer is the request
  `offer:<key>` until somebody answers it. A limited one is a request only while the registration holds its
  seat or has an answer (so a refused offer, which gave its seat back, can be answered "yes" again).
- Only this module writes `kalaka_support_offers`. Offers are settings, so an offer left out is deleted; the
  directory's nodes are never deleted.
