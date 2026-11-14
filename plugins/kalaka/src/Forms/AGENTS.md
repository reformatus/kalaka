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
  later [Changing participant fields after submit](../../../../docs/architecture/events-and-forms.md#changing-participant-fields-after-submit),
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
  label, by the same rules in two places: the form editor
  (`Blocks/form/keys.js`) sets them in the block when the field or option is
  created, and gives a copied field fresh ones; on save, `FieldKeys` (the only
  writer on the server) derives any that is still missing or invalid. After
  that they never change, whatever the text becomes. Only a missing or invalid
  key, or a missing, invalid or repeated option value, is derived again, since
  a form holding one was unusable and stored nothing. A key repeated within one
  form is never derived again: the save check refuses it (`duplicate_key`).
- A form's fields are its event's participant fields
  (`kalaka_participant_fields`, owned by `ParticipantFields`; Forms reads and
  writes them only through `ParticipantFields\Api\ParticipantFields`). A derived key is unique
  across the event and never takes a key the event ever had, retired ones
  included. A key the form holds on purpose that another form of the event
  holds too is the same participant field and must have its type; an
  `extension` field not declared form-writable, like a `system` field, is
  read-only and no form holds its key; a `staff` field stays writable from
  forms. A block that arrives without its key takes back its stored key
  (same type and title) before anything is derived. A choice field's options are
  the union over the event's forms; one no form holds is retired, its label
  kept.
- A participant field's title and its choice options' labels are its own,
  separate from the labels the forms show: each is set when it is first
  declared (an option is matched by value), never from a later form's label,
  and changed only by `ParticipantFields::rename()` (a `form` or `staff` field; a
  `system` or `extension` field's texts are its declarer's). Mails, views
  and the status page show the participant field's labels; a form shows its
  own.
- A form's event is set when it is created and fixed once the form has
  fields or submissions refer to it (`Registrations\Api\FormSubmissions`),
  on every path that writes the setting, with one known gap: code that sets
  the first event of a form that has fields but no event through
  `update_post_meta()` directly is let through, because the REST create sets
  the event that way after the post exists (`FormEvent`). A save that changes the
  event is judged before anything is stored, on the stored and the incoming
  content: it is refused whole, never stored with its setting refused.
- The participant-field sync never stops on one clashing form: it declares
  the rest, logs the clashing keys, and a published form whose key clashes
  with the event's field is unusable and named in the broken-form warning.
- A post save never writes Kaláka's tables: the save checks only read and
  refuse (REST 400, else a graceful veto with an admin notice), and
  `ParticipantFieldSync`, after the post and its settings are stored, writes
  the participant fields through `ParticipantFields::declare()`; besides it
  only the rename route (an explicit request) does, through
  `ParticipantFields::rename()`.
- The form editor derives a key unique across the form's event: against the
  event's participant fields, retired ones included, read when the editor
  knows the event and again after every save (`Blocks/form/event-keys.js`),
  and the form's own keys. While that list loads, or when it cannot be read,
  it derives no key and says so in the canvas. Only a key the author types or
  picks may match one of the event's keys.
- A field block that arrives (pasted or inserted) with the key of one of the
  event's participant fields that the form did not hold when last saved is
  never kept silently and never joins implicitly
  (`Blocks/form/participant-prompts.js`): the author chooses to join a field of
  the same type that forms may write, or gets a fresh key as a copy does
  (`keys.js`, `copied`); a field of another type or one no form writes gives a
  fresh key and a snackbar says so.
- A block's label and option labels never change its participant field's
  title or option labels by itself (options matched by value); the editor
  asks, and only the rename route (`PATCH …/participant-fields/{key}`, through
  `renameParticipantField` in `participant-store.js`) changes them. Joining a
  choice field never replaces the form's own option labels.
- The form editor is one script (`Blocks/form/index.js`) for every Kaláka
  block, so all field blocks share one keeper of keys. JavaScript never evaluates a condition. The
  editor checks a condition against the server's rules (`ConditionParser`,
  `FormScope`, with the types, keys and bounds the server prints) only to show
  problems while editing; the server's check on save stays the judge.
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
  the form exists; a form that submissions refer to is never deleted
  permanently (`FormDeletion` on `pre_delete_post`, and the REST controller's
  forced delete); moving it to the trash is allowed.
- A form with a definition problem (keys, options, an unknown field block, the
  slot's markings, a missing revision, a key that clashes with the event's
  participant field) is unusable; it is never half-accepted.
- A published form never goes from working to broken through an edit: saving a
  form as published or scheduled runs the live form's definition check
  (`DefinitionCheck`, through `DefinitionParser` and `SharedKeyCheck`) and
  refuses a broken one on every save path. The definition check has two homes, and both the save check
  and `Forms::definition()` run both (`Forms::definition()` runs the event
  check on the current version only; an earlier version is read as it was): a check of the form alone goes into
  `DefinitionParser`, a check against the event's participant fields into
  `ParticipantField\SharedKeyCheck`. A form broken before the check stays
  closed and is named in a site warning; it is never rendered from another
  revision. A scheduled form is checked again when WordPress publishes it, and
  a broken one goes back to draft instead.
- Kaláka's form blocks are gated by post type (`BlockGate` on
  `allowed_block_types_all`), never by `ancestor`: a form's editor gets the
  allow-list, any other editor only what a `BlockHost` names. Outside a form's
  editor an allow-all `true` stays `true` (blocks registered only in JavaScript
  must survive); the gated blocks are hidden there on the client. Forms never
  names another module's post type; that module contributes the host. The
  condition blocks are not gated: every block editor offers them.
- Every form in the registration slot has exactly one contact email and one
  display name; additional forms have neither.
- Access checks are pure: they read no current user, cookie or request; the
  caller passes the roles and whether the password was given.
- `Forms\Api` reads WordPress posts, so it runs before or after a use case's
  transaction, never inside it. The one exception is `Forms::refusals()`,
  which reads nothing: a submit checks it under the event's lock.
- Field types contribute through the typed point and return values; no field
  type writes to a table. Core ships no money-relevant field type.
- Forms checks answers and hands them over; only Registrations writes
  submissions and field values.
- One availability base (S76): everything shown or selectable (fields,
  branches, pages, choice options, set options, tiers, offers, aid) is
  decided by one condition through `Condition\ConditionEvaluator`, reached
  through `FormWalk` (a form) or `Api\Availability` (anything another module
  owns). No second evaluator anywhere, and JavaScript never evaluates a
  condition (the editor only checks one against the server's rules to show
  problems).
- A condition is evaluated purely, from a fact source (`Api\Facts`): no
  database, hook, current user, request or clock. Whoever evaluates fills the
  fact source; the evaluator never runs the materializer on a draft.
- A form is walked the same way for a draft's re-render and at submit
  (`FormWalk`). In a form, a condition reads only answers of fields before it
  and the event's keys (`Api\EventValues`). At submit, an answer in a branch
  that does not hold, or an option that is not offered, is dropped: never
  stored, never required, never an error.
- A form's chains are numbered once, by `Condition\ChainMarks`, on the parsed
  blocks: `DefinitionParser` reads the numbers from those marks and the branch
  blocks render them (`data-kalaka-chain`, `data-kalaka-branch`), so nothing
  else, in PHP or JavaScript, recomputes them.
- No chain is ever pending: an unanswered field, a required or optional
  single choice included, reads as empty, so a chain on it shows its `else`
  until it is answered. Validation follows what is shown: at submit an
  unanswered required choice has its own required error, and the fields of
  any branch shown at that moment (an `else` among them) are checked like any
  shown field.
- Only answer-driven chains ship every branch to the page; a chain that reads
  any other key ships only its held branch (`FormDefinition::withheldChains()`,
  `ChainMarks::prune()`).
- The after-submit section (`Api\AfterSubmit`) is at most one block, at the
  top level outside any branch or page, with no fields; its chains are not the form's (not numbered,
  not walked) and read only `AfterSubmit::TYPES`.
- A page that places a form (the block `kalaka/form` or the `[kalaka_form]`
  shortcode) is never cached: `Api\FormCaching::preventCaching()` on
  `template_redirect`, and again from the form renderer.
- A form has no page or all its content in top-level `kalaka/form-page`
  blocks (with the one top-level `kalaka/after-submit` beside them); pages
  are numbered once, by `Condition\ChainMarks`, like chains. A page's
  condition reads only earlier pages and `event.*`; page 0 has none; a
  terminal page has one and holds no field. A page is one more gate in
  `FormWalk`: a skipped page's fields and chains do not show and its answers
  are dropped. Pages are not withheld like held-fact branches: every page
  ships hidden, whatever its condition reads (#167 MP-16). A submit while a
  terminal page shows is `Api\FormTerminal`
  (`AnswerValidator`). The contact email and display name never sit on a
  conditional page (`SlotRules`).
- A page's errors come only from `Forms::availability()` with `$page`: the
  walk's errors filtered to that page's shown fields.
- A re-fetch or a submit accepts only the form's latest version
  (`Forms::latestDefinition()`); an earlier one is `FormOutdated`.
- The form page's two public routes (`Rest\FormRoutes`, `kalaka-portal/v1`)
  are registered only through `Boundary::route()` and call
  `LoadState::requireOpen()` first. The availability route only reads: it
  never runs a use case or writes. The submit route calls `SubmitGuard` before
  the submit seam (`Registrations\Api\RegistrationSubmit`). Every answer of
  both, refusals included, carries `Cache-Control: no-store`. A malformed body
  is `InvalidInput`, never a WordPress argument error.
- Reading a key that a value context does not declare is a failure, never an
  empty value. A document (a mail body) stops at it; on an ordinary page the
  failing chain or value renders nothing and the failure is logged, never a
  fatal.
- A condition reads: in a form, its answers; in a mail, the
  recipient's registration; elsewhere, the visitor's active registration and
  its event. With no active registration, no branch of a chain renders.
- A value is plain text, escaped where it renders; inline values are replaced
  with the HTML API.
- Number comparison in a condition uses the bundled `Kalaka\Vendor\Brick\Math`
  in the evaluator (deptrac: the `Forms` layer); no `Forms\Api` signature takes
  or returns a brick/math type.
- The script keeps every answer while the page is open and sends only the
  answers of fields that show; it keeps nothing across a reload.
- The submit token (`Registrations\Api\SubmitToken`) is issued by the server
  on every render of a form page, into a hidden field, and sent back
  unchanged on every send of that page; it never goes into a cookie,
  storage, a URL or a log line.
- A `kalaka/` block in a published form that is not a registered block type
  closes the site.
- One field type per block name; a second one is a load failure.
