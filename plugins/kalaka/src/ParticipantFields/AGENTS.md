# ParticipantFields

ParticipantFields is the event's participant fields: the list each event
keeps of what it knows about its participants
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- events-and-forms.md: the participant-field list of
  [Fields](../../../../docs/architecture/events-and-forms.md#fields) (sources,
  the field's own title and option labels, retired fields). How forms derive
  keys, join a field and declare it is Forms'.
- modules-and-extensions.md: its own table under
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The table `kalaka_participant_fields`, keyed by (event ID, field key), is
  this module's schema (option `kalaka_schema_participant_fields`), and
  `ParticipantFieldRepository` is its only writer.
- Two audited use cases write it, `DeclareParticipantFields`
  (`kalaka/declare-participant-fields`) and `RenameParticipantField`
  (`kalaka/rename-participant-field`). Every other module reads and writes
  only through `Api\ParticipantFields` (`of()`, `declare()`, `rename()`),
  whose writes run the use case through the runner.
- `of()` reads outside a use case's transaction, never inside it: a caller
  that needs the fields in a use case reads them first and passes them in.
- A field is never deleted and its key never given to another field: a
  `form` field no form holds any more is retired, and so is a `form` field's
  choice option no form holds, with its label kept.
- A field's title and option labels are its own: set when it is first
  declared (an option matched by value), changed only by
  `RenameParticipantField`, for a `form` or `staff` field; a `system` or
  `extension` field's texts are its declarer's.
- A field's `type` is an opaque block name to this module, and it reads
  nothing of Forms: which forms hold a field is Forms' to add
  (`ParticipantField::withForms()`).
