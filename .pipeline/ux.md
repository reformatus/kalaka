# UX notes for Kaláka

Today `plugins/kalaka/` registers the public form blocks and the
`[kalaka_form]` shortcode, and the per-event „Jelentkezők” grid with its
menus and read REST routes. This file grows as pages are built.

Who uses it:

- Organisation staff, in wp-admin.
- Registrants, on the front end.

Always check: every visible text is Hungarian and approved, and nothing
unapproved appears in the UI.

Phone width: the admin pages „Jelentkezők” and „Levelek” and the
registrant portal are used on phones. When a change touches their layout,
check them at 390 px as well as on a desktop:

- the columns that identify a registration and its status, and the primary
  actions, must be reachable without scrolling the page sideways;
- prefer an overflow menu (⋮) for secondary actions over a row of buttons
  that wraps.
