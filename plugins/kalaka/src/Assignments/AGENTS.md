# Assignments

Assignments is assignment sets, options, assignment facts, tallies, roster
views and the proposer point
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).
S44 builds request mode: sets, options, the facts, the tie to the request
field and staff placing by hand. Tallies, rosters, the proposer (S77), direct
mode (S65) and the screens (S72/S48) come later.

## Sections it implements

- modules-and-extensions.md: [Assignment sets](../../../../docs/architecture/modules-and-extensions.md#assignment-sets)
  (request mode, staff placing).
- events-and-forms.md: the set options of the one availability base in
  [Conditions](../../../../docs/architecture/events-and-forms.md#conditions).
- foundation.md: assignments as facts in [Facts, decisions and computed
  values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values);
  [Entities, repositories and queries](../../../../docs/architecture/foundation.md#entities-repositories-and-queries).

## Invariants

- A registration holds at most one option per set: one `kalaka_assignments`
  row per (event, code, set), with its source (`staff`, `proposer`) and state
  (`proposed`, `confirmed`).
- An assignment is a fact: written only by use cases, audited on its
  registration (`assignment:<set ID>.<column>`), and never removed or changed
  by a rule or a later field change. Only staff (and later the proposer's
  confirmation) change it.
- A use case that writes an assignment locks the registration row first
  (`Registrations\Api\Registrations::lock()`); the assignment rows sit after it
  (`LockLevel::Other`).
- Staff placing (`kalaka/place-registration`) writes a confirmed `staff` fact,
  replacing what the registration held in that set; neither capacity nor
  eligibility stops it (staff override), but staff are warned first
  (`Api\AssignmentSets::placementWarnings()`, a plain read): the option is
  full (the seats held in it, not counting the registration's own, have reached
  its capacity), or the registration does not meet its condition. The option
  must be the set's and the set the registration's event's. The read takes the
  act's moment (`$now`, spread as `EventValues::now()` into its facts, for a
  condition that compares with "now"); a condition that cannot be read fails
  the read, it is never taken as a pass.
- A `registered` registration holds a seat in the pool of each option it is
  confirmed into (`OptionSeatPool`, `option.<set ID>.<option key>`, under the
  one seat rule of `Registrations\Api\Seats`): staff placing takes it under a
  lock on the option row, over the capacity if need be, and frees the seat of
  the option the registration held in the set before. A registration that is
  not `registered` (waitlisted, withdrawn, removed) is placed without a seat.
  Leaving frees the seats and the confirmed assignment stays as a record; an
  admission or a restore takes the seats again through `Seats::retake()`
  (`Api\AssignmentSeats::retake()`, which returns the options that went over
  the limit so the use case can warn staff). A proposed assignment holds none. The option's capacity
  is the pool's limit; nobody is moved into a freed seat.
- An option's eligibility is one condition in the one format, decided only
  through `Forms\Api\Availability` (an option is a `Forms\Api\Conditional`);
  this module never evaluates a condition. It is checked when saved against
  the event's participant fields and keys, and against every published form
  holding the set's request field, as that form would read it: a condition
  that would break one is refused (`Api\LiveFormWouldBreak`). Those forms are
  read before the run (`Forms\Api\Forms::requestScopes()`, through
  `Api\AssignmentSets::saveOption()`) and passed into the use case, which
  reads no WordPress post in its transaction.
- An option key is derived once from the label (`Forms\Api\KeyDerivation`),
  unique within its set, and never changes.
- A set has at most one request field (`request_field_key`), tied once
  (`kalaka/tie-request-field`) and never changed or released; one field is the
  request field of one set only.
- A set's `required` only marks the later tally; whether a form insists is
  the request field's own `required`.
- Per-event tables are indexed event first; only this module writes its
  tables. Other modules and extensions use `Api\AssignmentSets`.
