# ParticipantFields

The event's participant fields: one list per event of what it knows about each participant, by key.

At the module root: `ParticipantFieldRecord` (a row of `kalaka_participant_fields`, keyed by event ID and field key), `ParticipantFieldRepository` (its only writer; a field is the audit subject `participant_field`), `ParticipantFieldQuery` (an event's fields without a lock, in declaration order), and the two use cases: `DeclareParticipantFields` (`kalaka/declare-participant-fields`: brings an event's fields in line with the fields its forms hold; declares new keys, unions choice options, retires what no form holds) and `RenameParticipantField` (`kalaka/rename-participant-field`: a `form` or `staff` field's title and option labels).

`Api/` holds `ParticipantField` (one field as everyone else reads it; `withForms()` adds the forms that hold it) and the facade `ParticipantFields`: `of(eventId, includeRetired)` reads an event's fields with their type labels (the block type's registered title), `declare(eventId, fields, actor)` and `rename(eventId, key, changes, actor)` run the use cases through the runner. Forms' `ParticipantFieldSync` calls `declare()` after a form is saved and its `ParticipantFieldsRoute` calls `rename()`; Registrations' submit reads `of()` before its transaction.

Table, created by the schema in `Module.php`: `kalaka_participant_fields`.
