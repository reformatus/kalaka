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
- Timeline points are absolute; no point is defined relative to another.
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
