# Admin

Views and grants, data grid, detail views, notes, audit and settings.

- `Api/AdminAccess`: the admin side's coarse permission check, which the runner calls before an admin use case begins, and the full-access capability (`AdminAccess::FULL_ACCESS`).
- `Api/Access`: what an actor may reach, for the screens: `isFull()`, `grantedEventIds()`, `heldViews()` and `master()` (each a `HeldView`), `mayReachAdmin()` (the abilities' gate) and `mayWrite()` (the fine check of a field change).
- `Api/ViewQuery`: the one read path for registration data: `columns()`, `count()`, `page()` (a `ViewPage` of `ViewRow`s, sorted and paged), `contains()`.
- `Api/Abilities`: admin use cases as WordPress abilities.
- `View/`: `ViewShape` (filter and columns), `View`, `ViewRepository`, `ViewList`, and the use cases `SaveView`, `DeleteView`, `CopyViews` (nested).
- `Grant/`: `Grant`, `GrantRepository`, `GrantQuery`, and the use cases `SaveGrant`, `DeleteGrant`.
- `GrantFullAccess` (at the module root) and `FullAccess/RevokeFullAccess`: the two full-access use cases, both through `FullAccess/FullAccessChange` (the checks, the audit of the real old value, the capability written per user after the commit). `FullAccessOnActivation` runs the grant as the system for the activating user, only while nobody holds full access.
- `FullAccess/`: `FullAccessChange`, `RevokeFullAccess`, `FullAccessQuery` (the one holder rule: a user's own capability or a role carrying it, read from `usermeta`), the profile checkbox (`ProfileField`), the Users column (`UsersColumn`), and their Hungarian `Texts`.

Entry points: the abilities (`kalaka/save-view`, `kalaka/delete-view`, `kalaka/save-grant`, `kalaka/delete-grant`, `kalaka/grant-full-access`, `kalaka/revoke-full-access`), the profile and user-edit screens, the Users list, WP-CLI `wp kalaka full-access grant|revoke <user>` (`Cli/FullAccessCommand`), and the plugin's activation hook (`FullAccessOnActivation`).
