# Assignments

Assignment sets, options, assignment facts, tallies, roster views and the proposer point. S44 builds request mode.

Tables (`Module.php`, schema 1): `kalaka_assignment_sets` (a set of an event: name, time slot, required, mode, choice
window, published, `request_field_key`), `kalaka_assignment_options` (keyed by set and option key: position, label,
capacity, leaders, place, the eligibility condition as JSON in `eligibility`) and `kalaka_assignments` (the facts, keyed
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
checks). `Api\AssignmentOption` is a `Forms\Api\Conditional`.

Entry points: `wp kalaka assignment place <event> <code> <set> <option>` (`Cli\AssignmentCommand`); the
`GET kalaka-admin/v1/events/{id}/assignment-sets` route (`Rest\AssignmentSetsRoute`, full access), the form
editor's set picker.
