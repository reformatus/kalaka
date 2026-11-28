# UX notes for Kaláka

Today `plugins/kalaka/` registers the public form blocks and the
`[kalaka_form]` shortcode, and the per-event „Jelentkezők” grid with its
menus and read REST routes. This file grows as pages are built.

Who uses it:

- Organisation staff, in wp-admin.
- Registrants, on the front end.

Both are often on a phone: the admin pages „Jelentkezők” and „Levelek”
and the registrant portal must work at 390 px, with the columns that
identify a registration and its status, and the primary actions, reachable
without scrolling the page sideways.

Always check: every visible text is Hungarian and approved, and nothing
unapproved appears in the UI.

## Guiding principles

Reviews cite one principle per finding. General set: Nielsen's 10
usability heuristics (https://www.nngroup.com/articles/ten-usability-heuristics/),
with NN/g progressive disclosure
(https://www.nngroup.com/articles/progressive-disclosure/). WordPress layer:
the WordPress philosophy (https://wordpress.org/about/philosophy/). WCAG 2.2
AA is the floor, not a design guide.

- Progressive disclosure (NN/g). Each screen shows the primary task first.
  At 390 px a grid shows only its identifying columns and one primary
  action; secondary actions go one level down, into a WordPress
  `DropdownMenu`/"more" menu, never a full row per action.
- Aesthetic and minimalist design (H8). Every visible element must earn its
  place at phone width. If something only fits by squeezing, it moves down
  a level. It is never shrunk.
- Recognition rather than recall (H6). Row actions and filters stay visible
  or one tap away, and they use WordPress's own icons and labels. One
  primary action stays visible; everything else sits one tap down.
- Consistency and standards (H4). Use WordPress primitives and DataViews'
  built-in behaviour (its views, its actions menu) before anything custom.
  Kaláka has no look of its own.
- Visibility of status, error prevention and recovery (H1, H5, H9). Every
  save gives feedback where the user acted. Destructive actions confirm in
  a Modal. Errors say in Hungarian what to do next.
- Decisions, not options; design for the majority (WP philosophy). Choose
  the default and add no setting or toggle for it. Registrants, often
  parents or teenagers on a phone, see only what their task needs.
- Check every layout at 390×844 and 1280×800. Something that works only on
  desktop is a finding.

Fix proposals in a UX review name the principle they rest on. They are
suggestions for the owner (decision boxes), never built unasked.
