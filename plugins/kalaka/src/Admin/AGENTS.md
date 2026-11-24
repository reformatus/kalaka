# Admin

Admin is views and grants, the data grid, detail views, notes, audit and
settings ([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- foundation.md: [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants),
  and the admin side of the coarse permission check in
  [The use-case runner](../../../../docs/architecture/foundation.md#the-use-case-runner).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface)
  (the full-access capability and the minimal role belong to Admin).
- engineering.md: [Static checks](../../../../docs/architecture/engineering.md#static-checks)
  (admin reads registration data only through the view query).

## Invariants

- The admin-side coarse check is decided in one place, `Admin\Api\AdminAccess`;
  the runner calls it before the transaction begins, and no use case or entry
  point repeats or bypasses it.
- Full access is one site-wide capability, `kalaka_full_access`, given per
  user and never to a role; administrators do not get it by their role. It is
  given and taken only through `kalaka/grant-full-access` and
  `kalaka/revoke-full-access` (audited; the capability is written after the
  commit; the audit records the real old value), except that tests and the
  development seed's `kalaka_admin` add it directly. One holder rule,
  `FullAccessQuery::holders()`: a user holds it by their own capability or
  through a role that carries it. The last holder keeps it (use case, profile,
  CLI and abilities alike). The profile screen checks a change after
  WordPress has checked the rest of the form, and makes it only after
  WordPress has saved the user. Activation gives the activating user full
  access through `GrantFullAccess` (audited, as the system), only while
  nobody holds it by that rule.
- Per-event actions are carried by grants; they are never WordPress
  capabilities and are never added to roles. Only the minimal role
  (`kalaka_grantee`) touches WordPress roles.
- A grant holds a call only by name: an action the grant carries, or a field
  change with at least one target, every target inside the grant's view. An
  event use case with neither is full access only.
- Editing or deleting a view with grants is allowed (S36-4, owner ruling
  2026-11-16): removing or moving the parameter term takes the bound value
  from each of the view's grants (`SaveView`, audited); the parameter term
  applies only with a bound value, so a grant without one sees the whole
  view (`ViewQuery`); `DeleteView` deletes the view's grants, each through a
  nested `kalaka/delete-grant`. A grant holds nothing only when its view is
  missing or unreadable.
- The view is the only place permissions on data are defined. A grant never
  lists registrations: it covers what its view's filter matches when access is
  checked.
- On the admin side, registration data is read only through
  `Admin\Api\ViewQuery`, which reads only through a `HeldView` that `Access`
  hands out, or through full access. Only `ViewQuery` names a registration
  table (`RegistrationTablesTest`); it never selects the secret.
- The abilities' permission callback, and the gate of the grid's
  `kalaka-admin/v1` routes, let through only full access or a grant holder;
  the runner's coarse check still decides each call.
- The master view's counted members live in the event's master row of
  `kalaka_views` (`master` 1): at most one per event (the unique key on
  `master_event`), written only through `ViewRepository`, by `CopyViews`
  (nothing in the grid writes it). Its name, filter and columns are stored
  empty and never read. It is never listed, edited or deleted as a view. A
  grant on the master view keeps `view_id` null and never points at the master
  row.
- The grid's ad hoc filters and search (`Narrowing`, S37) only narrow: the view query appends them to the held
  view's own filter and bound value, never replaces them, so a grant holder never reaches a row the view does
  not hold. They apply only to the view's listed columns (anything else is `InvalidInput`), and their kind comes
  from the column's own type (`GridNarrowing`), never from the client. They reach `page()` (and its total) and
  `aggregates()` only; `count()` for the dialog, `contains()` and the access checks never see them. The
  sorszám range is computed in SQL as `RegistrationList` computes it. Text matches ignore case and accents
  (`utf8mb4_unicode_520_ci`); a stored `1` is yes, a stored `0` is no and no value is neither.
- The counted members of a view (`counts`) are stored only by full access, and
  each is one of its column's members. Any holder of a view, full access
  included, may send an ad hoc `counts` override with the sums read: it is
  validated against the held view's listed columns and their members, applies
  to that request only, and is never stored and never widens the view.
- The fine check of a field change (`Access::mayWrite()`) runs inside
  `execute`, on a registration the run holds locked.
- The detail panel shows only what the registration detail read returns and
  never decides access: what it shows and which actions it offers come from
  the read (its `can` included), and the server decides every call.
- The detail read carries the registration's `requests` (with their latest answers and, for a refused offer, whether
  its pool is full) only to a holder of `kalaka_answer_request` on a view that holds the registration, or full
  access; `can.answer_request` says so. The answer act is `kalaka/answer-request` (Registrations); the panel's
  block of requests only calls it.
- A grid row carries `awaits_answer`, a bool computed per page by Registrations' `Requests::awaitsOf()` (one
  statement for the page's field values, none without a request source); it is a row value, not a column, so it is
  not filterable, sortable or summable.
- Staff correct a field value only through `kalaka/edit-field-value` (`FieldEdit\EditFieldValue`): a field change with
  no action, so rights are read-write columns of a held view and full access, never a new grant action. The field
  type turns the value into its stored form before the run (`FieldEditInput`), never inside it; no form condition is
  walked (staff override). Staff never edit a `system` field, an `extension` field only its own act writes, or a
  retired field. The edit is not a submission; the write goes through `Registrations\Api\FieldCorrections`, and the
  runner's evaluation (money, confirmation, mail) follows it; the edit sends no mail of its own. An identical value
  writes nothing, and a stored value other than `expected` is a `VersionConflict`. Admin never runs a field type:
  the stored form and the editor's data come from `Forms\Api\FieldEditing`. The ability registers with `$prepare`
  (`EditFieldValue::prepared()`) and takes `preview`, which does everything but the write and returns the
  `warnings` (the approved "full" text, `AssignmentSets::FULL_WARNING`) beside `changed` and `over_limit`. A preview needs no
  `reason` (the panel asks for the warning before staff type one); a save does.
- The detail read tells, for each field it lists, whether staff may edit it (`writable`: the field is one staff
  edit and the actor's held view lists its column read-write while holding the registration, the same rule as
  `Access::mayWrite()` as a plain read, read once for all columns with `Access::writer()`; the act re-checks). A
  writable field carries `edit`: `answer` (`kind`, the field type's block name, and `value`, its prefill),
  `expected` (the stored value exactly as the act compares it), `control` (`choice`, `multi_choice`, `consent`, `text`, `number`, or null when the type has no simple control; from `Forms\Api\FieldEditing`, never a list in Admin), `multiple` for a choice and, for a type with options, `options` (each
  with `full`) and, for a consent, `full`. `full` is `FieldCorrections::fullValues()`: the choice's pool would
  go over for this registration, true only for a `registered` one. Reading it writes no audit entry and moves no
  seat. Placing into a tier or an offer by hand is this same act on `price_tier` or the offer's key.
- The detail read's `can.admit`, `can.withdraw`, `can.remove` and `can.restore` are the act's grant action carried and
  the status allowing it now (`Registrations\Api\StatusActs`); the status acts are Registrations' use cases, and the
  command line runs them through `Registrations\Api\StatusActions` without a rule of its own: it previews first, prints
  the warnings and money rows, asks (`--yes` skips), then runs for real, as `set-field` does; a remove without `--reason` is refused before the question, from the
  preview's `requires_reason`. Whether an actor may see
  money is one read, `Access::mayViewMoney()`, used by the detail read and the status-act preview. [UNAPPROVED N670]
- Staff placing into a set option is not an edit: it stays `kalaka/place-registration`.
