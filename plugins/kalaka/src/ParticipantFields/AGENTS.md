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
- Three audited use cases write it, `DeclareParticipantFields`
  (`kalaka/declare-participant-fields`), `DeclareExtensionField`
  (`kalaka/declare-extension-field`) and `RenameParticipantField`
  (`kalaka/rename-participant-field`). Every other module reads and writes
  only through `Api\ParticipantFields` (`of()`, `declare()`,
  `declareExtensionField()`, `rename()`), whose writes run the use case
  through the runner, or nested in the extension's own use case for
  `declareExtensionField()`.
- `of()` reads outside a use case's transaction, never inside it: a caller
  that needs the fields in a use case reads them first and passes them in.
  `of()` reads only its own table and the block registry, fires no hook and
  writes nothing, so two callers read it inside a transaction: the
  evaluation, which types a registration's field values through
  `Forms::participantValues()` (S25), and a money setting's save, which
  checks the conditions and the fields it names (S25, S26).
- A field's type settings (the number field's bounds, decimals and affixes)
  are its JSON `settings` column, stored from the form on declare; the
  module keeps them and does not interpret them.
- A field is never deleted and its key never given to another field: a
  `form` field no form holds any more is retired, and so is a `form` field's
  choice option no form holds, with its label kept.
- A field's title and option labels are its own: set when it is first
  declared (an option matched by value), changed only by
  `RenameParticipantField`, for a `form` or `staff` field; a `system` or
  `extension` field's texts are its declarer's.
- An `extension` field's options are its declarer's too: `DeclareExtensionField`
  sets them (an option no longer declared is retired, its label kept), and
  the forms' union never adds or retires one. A declaration never takes over
  a key held by another source or with another type.
- A field's `type` is a block name. The module reads only that block's
  registered title from WordPress's block registry (the type label) and
  nothing of Forms: which forms hold a field is Forms' to add
  (`ParticipantField::withForms()`).
