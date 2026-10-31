# Review rules for Kaláka

- SQL targets MariaDB 10.3 and later.
- SQLite (Playground) is not supported beyond loading the site. Don't report
  SQLite incompatibilities, and never add SQLite-specific code.
- Times are stored in UTC and shown in the site's WordPress time zone, never a
  fixed zone ([Cross-cutting rules](../docs/architecture/foundation.md#cross-cutting-rules)).
- Money is rounded as in [Amounts](../docs/architecture/money.md#amounts).
- Changes must fit the architecture sections that the topic map in `AGENTS.md`
  lists for the area they touch.
