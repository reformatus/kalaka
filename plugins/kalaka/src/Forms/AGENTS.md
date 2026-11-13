# Forms

Forms is forms, the registration slot, field types, field definitions,
conditions, prefill and form links
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- events-and-forms.md: [Forms and the registration slot](../../../../docs/architecture/events-and-forms.md#forms-and-the-registration-slot),
  [Forms implementation](../../../../docs/architecture/events-and-forms.md#forms-implementation),
  [Fields](../../../../docs/architecture/events-and-forms.md#fields);
  later [Values and conditions](../../../../docs/architecture/events-and-forms.md#values-and-conditions),
  [Changing participant fields after submit](../../../../docs/architecture/events-and-forms.md#changing-participant-fields-after-submit),
  [Prefill from a link](../../../../docs/architecture/events-and-forms.md#prefill-from-a-link) and
  [Form links and admin submissions](../../../../docs/architecture/events-and-forms.md#form-links-and-admin-submissions).
- modules-and-extensions.md: the field-type and theme-adapter points of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface).
- engineering.md: the field markup of
  [WordPress surfaces](../../../../docs/architecture/engineering.md#wordpress-surfaces).

## Invariants

- A form is a `kalaka_form` post: its content is the definition, its settings
  are post meta and its password is the post password. It belongs to exactly
  one event, and its placement (the registration slot or an additional form)
  decides its purpose.
- The server accepts only declared fields of the form's definition, checked
  against the same definition the page was built from; a value the browser
  sends for anything else is refused.
- A field key and a choice option's value are derived once from the title or
  label, on save, by `FieldKeys` (the only writer); after that they never
  change, whatever the text becomes. Only a missing or invalid key, or a
  missing, invalid or repeated option value, is derived again, since a form
  holding one was unusable and stored nothing. A key repeated within one form
  is never derived again: the save check refuses it (`duplicate_key`).
- A form's fields are its event's participant fields
  (`kalaka_participant_fields`, owned by Forms). A derived key is unique
  across the event and never takes a key the event ever had, retired ones
  included. A key the form holds on purpose that another form of the event
  holds too is the same participant field and must have its type; an
  `extension` field not declared form-writable, like a `system` field, is
  read-only and no form holds its key; a `staff` field stays writable from
  forms. A block that arrives without its key takes back its stored key
  (same type and title) before anything is derived. A choice field's options are
  the union over the event's forms; one no form holds is retired, its label
  kept.
- A participant field's title is its own, separate from the labels of the
  blocks that hold it: set when the field is declared, never from a later
  block label, and changed only by `RenameParticipantField` (a `form` or
  `staff` field; a `system` or `extension` field's title is its declarer's).
- A form's event is set when it is created and fixed once the form has
  fields, on every path that writes the setting. A save that changes the
  event is judged before anything is stored, on the stored and the incoming
  content: it is refused whole, never stored with its setting refused.
- The participant-field sync never stops on one clashing form: it declares
  the rest, logs the clashing keys, and a published form whose key clashes
  with the event's field is unusable and named in the broken-form warning.
- A post save never writes Kaláka's tables: the save checks only read and
  refuse (REST 400, else a graceful veto with an admin notice), and
  `DeclareParticipantFields`, run through the runner after the post and its
  settings are stored, is the only writer of the participant fields.
- A form autosave is never a version: the version is the latest revision that
  is not an autosave.
- Forms are edited in the block editor by full access only: every capability
  and every REST read of `kalaka_form` needs `kalaka_full_access`.
- A theme adapter only returns data (classes per element role, a `div` or `p`
  wrapper, an error colour); core renders the markup and applies it. A failing
  adapter is skipped and the form renders without it.
- A form version is the ID of one of the form's revisions, every usable form
  has at least one, and revisions of forms are never pruned, so a submission's
  version resolves to the definition it was checked against for as long as
  the form exists; a form that submissions refer to is never deleted.
- A form with a definition problem (keys, options, an unknown field block, the
  slot's markings, a missing revision, a key that clashes with the event's
  participant field) is unusable; it is never half-accepted.
- A published form never goes from working to broken through an edit: saving a
  form as published runs the live form's definition check (`DefinitionCheck`,
  through `DefinitionParser` and `SharedKeyCheck`) and refuses a broken one on
  every save path. The definition check has two homes, and both the save check
  and `Forms::definition()` run both: a check of the form alone goes into
  `DefinitionParser`, a check against the event's participant fields into
  `ParticipantField\SharedKeyCheck`. A form broken before the check stays
  closed and is named in a site warning; it is never rendered from another
  revision.
- Kaláka's form blocks are gated by post type (`BlockGate` on
  `allowed_block_types_all`), never by `ancestor`: a form's editor gets the
  allow-list, any other editor only what a `BlockHost` names. Forms never names
  another module's post type; that module contributes the host.
- Every form in the registration slot has exactly one contact email and one
  display name; additional forms have neither.
- Access checks are pure: they read no current user, cookie or request; the
  caller passes the roles and whether the password was given.
- `Forms\Api` reads WordPress posts, so it runs before or after a use case's
  transaction, never inside it.
- Field types contribute through the typed point and return values; no field
  type writes to a table. Core ships no money-relevant field type.
- Forms checks answers and hands them over; only Registrations writes
  submissions and field values.
