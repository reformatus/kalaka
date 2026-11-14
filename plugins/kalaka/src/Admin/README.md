# Admin

Views and grants, data grid, detail views, notes, audit and settings.

`Api/AdminAccess` is the admin side's coarse permission check, which the runner calls before an admin use case begins. It owns the full-access capability (`AdminAccess::FULL_ACCESS`); the grant and view-filter checks join it with the views and grants.

`GrantFullAccess` gives one user the full-access capability (on the user, never on a role), audited. On plugin activation `FullAccessOnActivation` runs it as the system for the activating user, only when no user holds the capability.

Entry points: the plugin's activation hook.
