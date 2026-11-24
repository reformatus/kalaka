# Events

Events is events, their states, timeline points, date types, capacity and the
"full" latch; later the participant phase tracker
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- events-and-forms.md: [Events](../../../../docs/architecture/events-and-forms.md#events),
  [Event lifecycle](../../../../docs/architecture/events-and-forms.md#event-lifecycle),
  [Date references](../../../../docs/architecture/events-and-forms.md#date-references).
- registrations-and-identity.md: the capacity and "full" latch part of
  [Status, capacity and waitlist](../../../../docs/architecture/registrations-and-identity.md#status-capacity-and-waitlist).
- modules-and-extensions.md: [Failure isolation](../../../../docs/architecture/modules-and-extensions.md#failure-isolation)
  (a failed load treats every event as unpublished).

## Invariants

- An event's state changes only through its admin use cases, along
  draft → published ⇄ unpublished → archived; archived is read-only.
- "Open", "closed" and phases are computed from the points and the current
  time on every read; they are never stored and nothing fires at those moments.
- Timeline points are absolute UTC instants (`datetime(6)`, custom points
  in the `points` JSON); no point is defined relative to another. Input is
  a local `YYYY-MM-DD HH:MM` in the site time zone; `Instant` converts.
- Custom points are part of the event row: no table, repository or audit
  subject of their own; a change of points is a change of the event, covered
  by its `version`, and a removed point's id is never reused.
- The "full" latch is a fact: set only by the capacity check of a
  registration that does not fit, cleared only by reopening the event.
  Editing capacity, points or state never touches it.
- While `LoadState` is closed every event reads as unpublished; the stored
  state is never changed for it.
- Other modules read and lock events only through `Events\Api`; only Events
  writes its tables.
- The event row is the first lock level; another module locks it only through
  `Events\Api\EventCapacity::lockForRegistration()`, and only that path and
  `kalaka/reopen-event` write the latch.
- Only a published event takes the full latch.
- `currency` is `utf8mb4_nopad_bin`: compared exactly.
- The payment and account settings (`account_mode`, `payment_due_days`, the three
  transfer texts) are written only by `kalaka/update-event`, as optional keys; the
  readers take the texts as entered, so no shape beyond the column's length is checked.
- A change of the account mode to one with accounts is refused while a published
  registration form of the event lacks the account block
  ([Forms implementation](../../../../docs/architecture/events-and-forms.md#forms-implementation)).
  The forms are read before the run (`Api\Events::update()`; a use case reads no
  post) and the broken-form list is refreshed after it.
- `wp kalaka event` (`Cli/`) is a thin entry point: it changes only through the
  module's use cases, as the command line or as WP-CLI's `--user`, and prints a use
  case's own refusal. It checks nothing itself (no state, field or value rule): it runs
  the permission check first, then the use case. Nothing it does re-evaluates a
  registration, so it asks nothing except that `archive` (read-only for good) needs `--yes`.
  The file's `version` goes to the use case, whose `VersionConflict` is the one stale-write
  check, and `update --dry-run` runs `kalaka/update-event` with `preview: true`, which makes every
  check of a real run and writes nothing; the command keeps no version check or dry run of its own.
- The refusal of `kalaka/publish-event` for an event that lacks fields is `Api\EventNotReady`
  (`missing`: the field keys), and the refusal of an account mode a published form cannot take
  is `Api\AccountBlockMissing` (`forms`: the form IDs). Both are rule violations with the
  same Hungarian text and their own error codes; the keys and IDs are typed properties for the
  operator's console (`wp kalaka event`) and are never sent to REST (`toWpError()` is unchanged).
