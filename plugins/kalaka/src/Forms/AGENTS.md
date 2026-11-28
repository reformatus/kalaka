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
  the additional forms and the prefill from the active registration of
  [Changing participant fields after submit](../../../../docs/architecture/events-and-forms.md#changing-participant-fields-after-submit)
  and [Prefill from a link](../../../../docs/architecture/events-and-forms.md#prefill-from-a-link);
  later the link prefill and
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
- A field key and an option's value (a choice's, or any
  `Api\OptionsFieldType`'s) are derived once from the title or label, by the same rules in two places: the form editor
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
  kept. An `extension` field's options are its declarer's: a form joining it
  offers any subset of them and never adds one (`extension_option`); a number
  field that joins an `extension` field whose declarer fixed its decimals (an
  extra charge's) holds that many (`extension_decimals`).
- A participant field's title and its choice options' labels are its own,
  separate from the labels the forms show: each is set when it is first
  declared (an option is matched by value), never from a later form's label,
  and changed only by `ParticipantFields::rename()` (a `form` or `staff` field; a
  `system` or `extension` field's texts are its declarer's). Mails, views
  and the status page show the participant field's labels; a form shows its
  own, and the participant field's only where it leaves one empty (a field
  block's `label`, a choice option's `label` by value): the engine fills them
  from the host (`FormHost::labelFallbacks()`, `Engine\LabelFallback`), so the
  page, the definition and the answers' texts read the same.
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
  refuse (REST 400, else a graceful veto with an admin notice, a log line
  and, on WP-CLI, a warning and a failing exit), and
  `ParticipantFieldSync`, after the post and its settings are stored, writes
  the participant fields through `ParticipantFields::declare()`, each field with `money_relevant` from its block
  type (`MoneyRelevantFieldType`); besides it
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
  block, so all field blocks share one keeper of keys. An extension's field
  block reaches the same pieces (the keeper's hook, the inspector, the title and
  its list-view label, the choice's options editor, the participant-field and key panels) only through
  `window.kalakaFieldEditor`, which that script sets, and names its handle
  first in its `editorScript`; it has no copy of them. JavaScript never evaluates a condition. The
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
- A text-like field's label sits on its own line above its control, on
  every theme, an extension's field included: one structural rule in
  `Blocks/form/view.css` on a field's `label[for]`, outside the `kalaka`
  layer (owner, 2026-11-18). A consent's or a choice option's label, which
  wraps its control, stays beside it.
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
  display name; additional forms have neither. A registration form also holds every participant field an
  extension requires of its event (`Api\SlotRequirement`, asked through `FormHost::slotRequiredKeys()`: the
  price ladder's `price_tier`), required and outside any branch or conditional page
  (`SlotRules::requiredFieldProblem()`, `required_field_missing`). Like the account block it is event-dependent, so
  it is checked when a form is served (`FormEngine::definition()` with `$serving`) and when it is saved live
  (`DefinitionCheck`), never on an earlier version.
- An additional form writes only to the active registration
  (`Portal\Api\ActiveRegistration`) of its own event, while that registration
  is `registered` or `waitlisted` and the form is within its open period
  (`OpenPeriod`: each end a date reference in `kalaka_opens` / `kalaka_closes`,
  a fixed UTC instant or an event point; an end not set does not limit it,
  one that does not resolve closes the form). Its submit runs as that
  registration's `SecretLinkActor`; it never creates a registration.
- A form opens with its host's stored values (`FormHost::prefill()`; for an
  additional form the registration's current field values), each turned into
  its field's answer by `FieldType::prefill()` (null opens it empty: a type
  that refuses prefill). The page is walked and rendered for those answers;
  each field block renders its own (`FieldMarkup::prefilled()`).
- Access checks read the request's active registration (an additional form
  acts on it) and nothing else from the request besides the logged-in user:
  no other cookie or request state. The caller passes the roles and whether
  the password was given; the engine's `FormHost::refusals()` contract leaves
  the logged-in user to the host: `EventFormHost::refusals()` passes
  `is_user_logged_in()`, and a submit passes whether its actor is an account.
- `Forms\Api` reads WordPress posts, so it runs before or after a use case's
  transaction, never inside it. The exceptions are `Forms::refusals()`,
  which reads nothing: a submit checks it under the event's lock; and
  `Forms::participantValues()`, which reads no post, only
  `ParticipantFields::of()` (its table and the block registry): the
  evaluation types a registration's field values with it (S25), and a money
  setting's save checks its conditions against them (S26).
- Field types contribute through the typed point and return values; no field
  type writes to a table. Core ships no money-relevant field type. A field
  type's `valueType()` and `value()` fire no hook and write nothing (they may
  read), so the evaluation may call them inside the transaction; the rest of
  the point runs outside it. A type's `settings()` names the block settings
  the event's participant field keeps (the number field's bounds, decimals and
  affixes); `Forms::participantValues()` passes them to `value()`.
- A field type whose value is a path (`Api\PathFieldType`) is never compared
  with `equals`: the parser refuses it where the scope knows the key's field
  type (`FormScope` and a mail body's check through `DeclaredScope`'s path
  keys), and the editor mirrors it (`pathFieldTypes`). A fact source
  (`ContextScope`) knows no field types and does not refuse it.
- Forms checks answers and hands them over; only Registrations writes
  submissions and field values.
- One availability base (S76): everything shown or selectable (fields,
  branches, pages, choice options, the options a request field takes from a
  set, offers, aid) is decided by one condition through
  `Engine\Condition\ConditionEvaluator`, reached for a form's elements through
  `FormWalk`. Set options carry no condition of their own: the ranking block
  that names the set holds one per option. No second evaluator anywhere, and JavaScript never evaluates a
  condition (the editor only checks one against the server's rules to show
  problems).
- A condition is evaluated purely, from a fact source (`Api\Facts`): no
  database, hook, current user, request or clock. Whoever evaluates fills the
  fact source; the evaluator never runs the materializer on a draft.
- "Now" in a condition is the date reference `{"ref": "now"}`, read from the
  fact source's `Facts::NOW` (`EventValues::now($at)`: the moment the caller
  holds, one clock passed down, a datetime to the second in the site's time
  zone): a value-side reference never names the key `time.now` (the left
  side of a comparison may, as a datetime, N694), never read from a clock by the
  evaluator. A chain that reads it is withheld.
  One way: every fact source a condition is evaluated over carries it
  (`EventValues::now($now)` in each `ValueContext` built for conditions, with
  the act's own moment), so the parser makes no scope check for it and a
  source without it fails loudly; nobody catches that failure into a pass.
- A form is walked the same way for a draft's re-render and at submit
  (`FormWalk`). In a form, a condition reads only answers of fields before it
  and the host's keys (`FormHost::factTypes()`: the event's, `Api\EventValues`,
  and an additional form's stored values). At submit, an answer in a branch
  that does not hold, or an option that is not offered, is dropped: never
  stored, never required, never an error.
- The keys only a form's conditions read (`event.seats_left`,
  `event.points.<id>`) are `EventValues::volatile()`,
  never in `EventValues::of()`, which mails, the after-submit section and
  `RegistrationFacts` also read. An additional form also reads
  `registration.status` and, by key, the stored values of the event's
  participant fields it does not hold (a field the form holds is read from its
  answers: `DefinitionParser` drops its key from the host's list, and
  `FormScope` prefers the form's own field). `EventFormHost::typesOf()` and
  `factsFor()` are the one pair that declares and values them, for the page,
  the re-fetch and the submit's walk (`Forms::submitFacts()`) alike, and the
  editor lists the same keys (`Module::editorData()`). A branch that reads any
  of them is withheld. The submit's walk reads them as they are then.
- A key a revision's conditions read that the host says is gone (`FormHost::goneTypes()`: a custom point staff
  removed, and `choice_open.<field key>`, the dropped set choice window of an older revision, owner ruling
  2026-11-25) is declared as its type with value none when a definition is read, for every revision, so a removed
  point or key never closes a form or an older revision; the save check refuses a point (`unknown_point`) and
  a `choice_open.*` key (as any unknown key). Only conditions
  count: text that mentions a key declares nothing (`Engine\Condition\ConditionKeys`).
- A form's chains are numbered once, by `Engine\Condition\ChainMarks`, on the parsed
  blocks: `DefinitionParser` reads the numbers from those marks and the branch
  blocks render them (`data-kalaka-chain`, `data-kalaka-branch`), so nothing
  else, in PHP or JavaScript, recomputes them.
- No chain is ever pending: an unanswered field, a required or optional
  single choice included, reads as empty, so a chain on it shows its `else`
  until it is answered. Validation follows what is shown: at submit an
  unanswered required choice has its own required error, and the fields of
  any branch shown at that moment (an `else` among them) are checked like any
  shown field.
- True, false and unknown are separate. A comparison on a missing value (or
  against a missing date) is unknown, `not` leaves unknown unknown, only
  `empty` reads a missing value, and unknown counts as not matched. A shown
  consent left unticked stores `0` (an answer), a consent that never showed
  stores nothing.
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
  are numbered once, by `Engine\Condition\ChainMarks`, like chains. A page's
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
- The form page's two public routes (`Engine\Rest\FormRoutes`, `kalaka-portal/v1`)
  are registered only through `Boundary::route()` and call
  `LoadState::requireOpen()` first. The availability route only reads: it
  never runs a use case or writes. The submit route calls `SubmitGuard` (S14's
  rate limit, `RateLimiter`: per account when logged in, else per address from
  `ClientAddress`, which trusts a forwarded header only from a proxy the site
  names; every attempt counts; the count is a transient named by a keyed hash,
  never an address and never our tables; a failing count lets the submit
  through; over the limit, `RateLimited`, 429 with `Retry-After`) before
  the host's submit (`FormHost::submit()`; for an event form the submit seam
  `Registrations\Api\RegistrationSubmit`). Every answer of
  both, refusals included, carries `Cache-Control: no-store`. A malformed body
  is `InvalidInput`, never a WordPress argument error. WordPress's refusal of
  a stale `wp_rest` nonce on them becomes `kalaka_session_ended`, which the
  page shows with its link to log in again, never as the system failure.
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
  in the evaluator (deptrac: the `Forms Engine` layer); no `Forms\Api` signature takes
  or returns a brick/math type.
- A form opens with its host's stored values (`FormHost::prefill()`), each
  turned into its field's answer by `FieldType::prefill()` (null opens it
  empty: a type that refuses prefill). The page is walked and rendered for
  those answers; each field block renders its own (`Engine\FieldMarkup::prefilled()`;
  an extension's through `Api\FieldMarkup::prefilled()`).
  `EventFormHost` gives none yet.
- The script keeps every answer while the page is open and sends only the
  answers of fields that show; it keeps nothing across a reload.
- The submit token (`FormHost::submitToken()`; for an event form
  `Registrations\Api\SubmitToken`) is issued by the server on every render of a form page, into a hidden field, and sent back
  unchanged on every send of that page; it never goes into a cookie,
  storage, a URL or a log line.
- The form engine is `Forms\Engine` (parse, walk, validate, conditions, field
  types, values and markup, theme adapters, `BlockGate`, `ChainMarks`, the form
  posts, the form page and its two routes) with its contract, every
  `Forms\Api` type but the facade `Forms`, `EventValues` and `AfterSubmit`,
  which stay at their names (deptrac: the layers `Forms Engine` and
  `Forms Engine Api`). It may use only Foundation Api, its own Api, WordPress
  and brick/math: never Events, Registrations, ParticipantFields, Admin or
  Forms outside the engine.
- The engine asks one host, `Api\FormHost`, for everything about what a form
  belongs to: the facts a condition reads and their types, the access
  refusals and the window, the key problem against keys beyond the form, the
  stored values the form opens with, the options of the set a
  request field names, the submit token and the submit, the after-submit
  section's keys, the participant fields' texts for a label the form leaves empty (`labelFallbacks()`),
  and whether the form needs an account (`accountStep()`).
  Kaláka's event form, `EventFormHost`, is the first host; `Api\Forms` and
  the form page's wiring (`Module.php`, `Blocks/form/render.php`) hand it to
  the engine.
- A request field (`Api\RequestFieldType`, e.g. the module ranking; S44)
  that names an assignment set holds no options of its own: the definition
  parse fills its `options` from the host (`FormHost::requestOptions()`),
  each with the condition its block's `optionConditions` holds for its key,
  read like a choice option's condition (fields before it and the host's
  keys); every version reads its own block's conditions. A key of
  `optionConditions` that is no option of the set is refused when the form is
  saved (`unknown_option`) and ignored when the definition is read, so a
  removed option never closes the form. `FormEngine::blocks()`
  renders it with those attributes. A set has no choice window (owner ruling
  2026-11-25): the form's own open period and conditions decide when the
  field can be answered. A block of such a type that names no set
  (`requestSet()` null) keeps its own options and is no request field: the
  block's set attribute decides, and the type is then also an
  `OptionsFieldType`, whose checks apply only to a block that names no set.
- A set has one request field (S44-1): the save check (`SharedKeyCheck`, on
  every save and on every read of a live form's definition) refuses a request
  field naming a set the form's event does not have (`unknown_set`), a second,
  different field for a set, or a field that is already another set's
  (`request_field_taken`); the same key in another form joins it.
  `ParticipantFieldSync` ties each set to its field after the fields are
  declared (`Assignments\Api\AssignmentSets::tieRequestField()`); a tie is
  never released.
- A re-rendered choice or request field's new wrapper gets the event
  `kalaka:field-swapped` (`SWAPPED` in `Blocks/form/view.js`) with the
  values it was ticked with, for a field type's own script.
- A form whose host gives an account step (`FormHost::accountStep()`) shows
  to everyone; it is never redirected. Its account block (`Api\SignUp::BLOCK`,
  `Engine\AccountMarkup`) is the only way in: a live registration form of an
  event with accounts must hold it (`AccountBlock`, `account_block_missing`,
  existence only; `Forms::definition()` runs the same check against the
  event's current mode when the form is served, so a published form that
  lacks it, say after the event switched to accounts, stays closed and is
  named in the site warning like any broken form; a stored submission's
  definition is read as it was, so its mail keeps its recipient and its
  context), the editor offers it only where the form can have
  accounts, and anywhere else it renders nothing. Its button opens
  WordPress's login page in a window of its own (`SignUp::url()`); a login
  there lands on the return page (`SignUp::returnUrl()`), which tells the
  form's page by `postMessage` to its own origin only and closes. The
  script adds `popup=1` to the address it opens with `window.open` only
  (the plain link stays the same-tab fallback), so the return page of the
  window never redirects to a second form. The page
  then reads its REST nonce from WordPress (`admin-ajax.php?action=rest-nonce`)
  and re-fetches with the block re-rendered, never reloading and never
  overwriting a typed answer; without a message it checks on focus or
  visibility while it waits, and a send waits for that check. The page and
  the re-fetch never refuse for the account; in `mandatory` mode the submit
  does (`account_required`, the last of the form's refusals, so a form
  refused for another reason says that first), and the re-fetch gives the
  block's error for a page check while the block shows on that page. Every
  way back to the form built from a marker is the form's page found on the
  server (`SignUp::page()`), never an address from the request. Logged in,
  the contact email starts with the account's address, and the block names
  the account with a logout link back to the form.
- `account.logged_in` (`SignUp::LOGGED_IN`) is a fact of a registration
  form's conditions (`EventValues::FORM_TYPES`): `yes` or `no` on a form with
  an account step (yes only while someone is logged in), none on a form
  without one, at render, re-fetch and submit alike; true, false and none are
  never conflated. A chain that reads it ships every branch
  (`FormDefinition::withheldChains()`), since a login changes it in place.
- A `kalaka/` block in a published form that is not a registered block type
  closes the site.
- One field type per block name; a second one is a load failure.
- `Forms::withoutAccountBlock()` lists the published registration forms of an event
  that lack the account block a given account mode wants (the one rule is
  `AccountBlock::missing()`, which the save check asks for the event's mode), and
  `Forms::refreshBrokenList()` recomputes `BrokenForms` after a mode change: the
  two halves of the refusal in
  [Forms implementation](../../../../docs/architecture/events-and-forms.md#forms-implementation),
  which `Events\Api\Events::update()` runs around `kalaka/update-event`.
