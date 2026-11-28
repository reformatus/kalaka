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
  `FullAccessQuery::holders()`: a user holds it by their own capability only,
  never through a role, and `user_has_cap` makes every `user_can()` check
  agree; `OwnCapability::owns()` is the same rule on a user object, for the
  filter, the profile checkbox and the Users column. The last holder keeps it: the use case, the profile, the CLI and the
  abilities refuse; WordPress's users screen and REST refuse deleting them
  through `map_meta_cap`, and `wp user delete` stops on the `delete_user`
  action; plain code calling `wp_delete_user()` past the check is logged and
  the deletion proceeds. Not guarded: `wp user remove-cap`, `wp user delete
  --network` (which fires `wpmu_delete_user`, not `delete_user`) and a direct
  `remove_cap()`. The profile screen checks a change after
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
- The display names of a list of registrations are read through
  `ViewQuery::namesOf(HeldView, list<string>)`, at most `MAX_NAMES` (100) codes
  (more is `InvalidInput`): the value of the display-name field of the slot
  form's submitted version, resolved through `Forms`, returned only for a code
  the view's filter holds and whose `field:<name>` column the view lists; null
  otherwise (a viewer without name access, a code outside the view, no answer).
  Full access (the master view) sees every name.
- The abilities' permission callback, and the gate of the grid's
  `kalaka-admin/v1` routes, let through only full access or a grant holder;
  the runner's coarse check still decides each call.
- The master view's counted members live in the event's master row of
  `kalaka_views` (`master` 1): at most one per event (the unique key on
  `master_event`), written only through `ViewRepository`, by `CopyViews`
  (nothing in the grid writes it). Its name, filter and columns are stored
  empty and never read. Its columns are composed by rule (owner 2026-11-27): every core column
  (`ViewShape::CORE_COLUMNS`), every field key, every decision kind and every computed column
  (`ViewShape::COMPUTED_COLUMNS`, today `awaits_answer`); a new column joins one of those lists, never a hand list. It is never listed, edited or deleted as a view. A
  grant on the master view keeps `view_id` null and never points at the master
  row.
- The grid's ad hoc filters and search (`Narrowing`, S37) only narrow: the view query appends them to the held
  view's own filter and bound value, never replaces them, so a grant holder never reaches a row the view does
  not hold. They apply only to the view's listed columns (anything else is `InvalidInput`), and their kind comes
  from the column's own type (`GridNarrowing`), never from the client. They reach `page()` (and its total) and
  `aggregates()` only, and `codesOf()`, which returns the codes of every matching row (no paging limit, the
  same conditions as `page()`) for a bulk act on "all rows the grid shows" (`Api\GridSelection`, through the one
  held-view path `Access::viewOf()` that the rows route also takes); `count()` for the dialog, `contains()` and
  the access checks never see them. The
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
- The detail read carries the registration's `requests` (with their latest answers, the request's participant field
  keys as `fields: {ask, answer}` and, for a refused offer, whether its pool is full) only to a holder of
  `kalaka_answer_request` on a view that holds the registration, or full access; `can.answer_request` says so, and
  is true only while the registration is `registered` (the grid
  column's meaning: nothing awaits on a waitlisted, withdrawn or removed one). An unanswered request on such a
  registration reads `inactive`, not `awaiting`; its answered ones keep their answers. The requests' money figures —
  an item's `amount` and, for an aid request, `aid: {full_price, can_pay, asked, max_award}` from the request
  source's `terms()` — follow the read's money gate (`can.view_money`), so a holder whose view/grant gives no money
  fields reads the requests without them. The answer act is `kalaka/answer-request` (Registrations); the panel's
  block of requests only calls it.
- "Awaits an answer" is a core-derived grid column, `awaits_answer`, like `status` and `serial`: a view lists it or
  not, any view may (it tells that something awaits, not what), and it filters (yes or no, no „Nincs válasz”),
  sorts (yes first descending) and counts (`yes`) like the other yes/no columns, all over the one SQL expression
  `ViewQuery::awaiting()`. That is `Requests::awaitingCondition()`, three-valued (owner 2026-11-27, N/A is different
  from „Nem”): for a `registered` registration it is yes when some request source's `awaitingRule()` (declarative;
  core compiles it) holds and no otherwise; for every other status it is N/A, NULL in SQL, never false. A filter on
  yes or no never matches N/A (`NOT` of NULL is NULL); N/A sorts with no value (last descending, first ascending);
  the grid shows it as „–”. It is never a filter term or a counted-members column. A grid row carries
  `awaits_answer` as true, false or null (N/A) from the same expression in the page's one statement, whether or not
  the view lists the column.
- The mail pages (`Mail\MailPages`, `app/mail/`) are full access's and decide no
  access: they show only what the mail reads return, every change is an ability
  call, and the page reads again after it; a typed or chosen draft is dropped
  only when its act succeeded.
- The grid's bulk action „Levél küldése” is offered on a tab only when the
  page's configuration says the tab carries `ManualMails::GRANT_ACTION` (full
  access: always); that is only an offer. It sends the ticked rows' codes as a
  `{ codes }` selection, and the preview and `kalaka/send-manual-mail` decide
  access again on every call (`kalaka_manual_mail`, inside the grant's view).
- Staff correct a field value only through `kalaka/edit-field-value` (`FieldEdit\EditFieldValue`): a field change with
  no action, so rights are read-write columns of a held view and full access, never a new grant action. The field
  type turns the value into its stored form before the run (`FieldEditInput`), never inside it; no form condition is
  walked (staff override). Staff never edit a `system` field, an `extension` field only its own act writes, or a
  retired field. The edit is not a submission; the write goes through `Registrations\Api\FieldCorrections`, and the
  runner's evaluation (money, confirmation, mail) follows it; the edit sends no mail of its own. An identical value
  writes nothing, and a stored value other than `expected` is a `VersionConflict`.
- Staff placing into a set option is not an edit: it stays `kalaka/place-registration`.
