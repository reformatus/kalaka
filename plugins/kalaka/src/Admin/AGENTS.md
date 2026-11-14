# Admin

Admin is views and grants, the data grid, detail views, notes, audit and
settings ([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- foundation.md: [Views and grants](../../../../docs/architecture/foundation.md#views-and-grants),
  and the admin side of the coarse permission check in
  [The use-case runner](../../../../docs/architecture/foundation.md#the-use-case-runner).
- modules-and-extensions.md: [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface)
  (the full-access capability and the minimal role belong to Admin).

## Invariants

- The admin-side coarse check is decided in one place, `Admin\Api\AdminAccess`;
  the runner calls it before the transaction begins, and no use case or entry
  point repeats or bypasses it.
- Full access is one site-wide capability, `kalaka_full_access`. Per-event
  actions are carried by grants; they are never WordPress capabilities and are
  never added to roles. Only the full-access capability and the minimal role
  touch WordPress roles.
- Activation gives the activating user full access, as that user's own
  capability through `GrantFullAccess` (audited, as the system), and only while
  no user holds it.
- The view is the only place permissions on data are defined. A grant never
  lists registrations: it covers what its view's filter matches when access is
  checked.
- On the admin side, registration data is read only through the view query or
  through full access.
