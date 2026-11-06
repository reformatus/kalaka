# Forms

Forms is forms, the registration slot, field types, field definitions,
values and conditions, prefill and form links
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- events-and-forms.md: [Forms and the registration slot](../../../../docs/architecture/events-and-forms.md#forms-and-the-registration-slot),
  [Forms implementation](../../../../docs/architecture/events-and-forms.md#forms-implementation),
  [Fields](../../../../docs/architecture/events-and-forms.md#fields),
  [Values and conditions](../../../../docs/architecture/events-and-forms.md#values-and-conditions)
  with [Conditions](../../../../docs/architecture/events-and-forms.md#conditions);
  later [Editability after submit](../../../../docs/architecture/events-and-forms.md#editability-after-submit),
  [Prefill from a link](../../../../docs/architecture/events-and-forms.md#prefill-from-a-link) and
  [Form links and admin submissions](../../../../docs/architecture/events-and-forms.md#form-links-and-admin-submissions).
- modules-and-extensions.md: the field-type point of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface).

## Invariants

- A form is a `kalaka_form` post: its content is the definition, its settings
  are post meta and its password is the post password. It belongs to exactly
  one event, and its placement (the registration slot or an additional form)
  decides its purpose.
- The server accepts only declared fields of the form's definition, checked
  against the same definition the page was built from; a value the browser
  sends for anything else is refused.
- A field key is stable: authored once, never generated or renamed by code.
- A form version is the ID of one of the form's revisions, every usable form
  has at least one, and revisions of forms are never pruned, so a submission's
  version resolves to the definition it was checked against for as long as
  the form exists; a form that submissions refer to is never deleted.
- A form with a definition problem (keys, options, an unknown field block, the
  slot's markings, a missing revision) is unusable; it is never half-accepted.
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
- A condition is evaluated purely, from a value context or a form's answers:
  no database, hook, current user, request or clock.
- In a form, a condition reads only answers of fields before its chain. At
  submit, an answer in a branch that does not hold is dropped: never stored,
  never required, never an error.
- Reading a key that a value context does not declare is a failure, never an
  empty value.
- A value is plain text, escaped where it renders; inline values are replaced
  with the HTML API.
- Number comparison in a condition uses the bundled `Kalaka\Vendor\Brick\Math`
  in the evaluator (deptrac: the `Forms` layer); no `Forms\Api` signature takes
  or returns a brick/math type.
