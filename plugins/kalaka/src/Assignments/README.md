# Assignments

Assignment sets, options, assignment facts, tallies, roster views and the proposer point. S44 builds request mode.

Tables (`Module.php`, schema 1): `kalaka_assignment_sets` (a set of an event: name, time slot, required, mode, choice
window, published, `request_field_key`), `kalaka_assignment_options` (keyed by set and option key: position, label,
limit (the column is still `capacity`), leaders, place, the eligibility condition as JSON in `eligibility`) and `kalaka_assignments` (the facts, keyed
by event, code and set: option, source, state). `SetRepository`, `OptionRepository` and `AssignmentRepository` are their
only writers.

Use cases: `SaveAssignmentSet` (`kalaka/save-assignment-set`), `SaveAssignmentOption` (`kalaka/save-assignment-option`;
derives a new option's key, checks its condition with `Forms\Api\Availability::check()`), `TieRequestField`
(`kalaka/tie-request-field`, run by Forms' participant-field sync after a form save) and `PlaceRegistration`
(`kalaka/place-registration`).

`Api\AssignmentSets` reads a set with its options (`find()`, `ofEvent()`) and a registration's assignment
(`assignment()`), and runs the save and tie use cases. `Api\AssignmentSets::placementWarnings()` is a plain read of
what staff are told before placing by hand (`PlacementWarnings`): the option is full, or the registration does not
meet its condition; it gives no warning for a placement the placing would refuse (`PlacementLookup` runs the same
checks). `Api\AssignmentOption` is a `Forms\Api\Conditional`; its `limit` is the same name on input (`SaveAssignmentOption`)
and on read. The warning "full" is given only to a registration that would take a seat (`registered`).

Seats: `OptionSeatPool` is an option's `Registrations\Api\SeatPool` (key `option.<set ID>.<option key>`, lock row the
option's, limit the option's `limit`), and `AssignmentSets::seatPool()` hands it out. The module contributes a
`Registrations\Api\SeatClaimSource` with `Contributions::seatClaimSource()` (`SetOptionClaimSource`): `pools()` names every option
of a set with a request field, by that field, and `claims()` gives none (a request takes no seat). `PlaceRegistration` takes
the seat through `Seats::retake()` for a `registered` registration only, and releases the one it held.

Entry points: `wp kalaka assignment place <event> <code> <set> <option>` (`Cli\AssignmentCommand`); the
`GET kalaka-admin/v1/events/{id}/assignment-sets` route (`Rest\AssignmentSetsRoute`, full access), the form
editor's set picker.
