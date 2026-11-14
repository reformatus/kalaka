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
- A view edit never widens access: `SaveView` refuses to remove or move the
  parameter term while a grant binds a value to it, and a grant whose bound
  value does not fit its view holds nothing (the view query matches nothing).
- The view is the only place permissions on data are defined. A grant never
  lists registrations: it covers what its view's filter matches when access is
  checked.
- On the admin side, registration data is read only through
  `Admin\Api\ViewQuery`, which reads only through a `HeldView` that `Access`
  hands out, or through full access. Only `ViewQuery` names a registration
  table (`RegistrationTablesTest`); it never selects the secret.
- The abilities' permission callback lets through only full access or a
  grant holder; the runner's coarse check still decides each call.
- The fine check of a field change (`Access::mayWrite()`) runs inside
  `execute`, on a registration the run holds locked.
