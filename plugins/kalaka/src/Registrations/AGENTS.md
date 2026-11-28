# Registrations

Registrations is registrations, field values, submissions, presets, statuses,
the waitlist and reference codes
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- registrations-and-identity.md: [Registrations](../../../../docs/architecture/registrations-and-identity.md#registrations),
  [Identity: no person records](../../../../docs/architecture/registrations-and-identity.md#identity-no-person-records),
  [Submissions and values](../../../../docs/architecture/registrations-and-identity.md#submissions-and-values),
  the status, sorszám and submit-time capacity decision of [Status, capacity and waitlist](../../../../docs/architecture/registrations-and-identity.md#status-capacity-and-waitlist),
  [Reference codes](../../../../docs/architecture/registrations-and-identity.md#reference-codes),
  the seat rule of [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  and [Quota places](../../../../docs/architecture/money.md#quota-places) (`Api\Seats`),
  the secret links of [Access](../../../../docs/architecture/registrations-and-identity.md#access),
  the owner account of [Accounts on the registration form](../../../../docs/architecture/registrations-and-identity.md#accounts-on-the-registration-form).
- foundation.md: the registration rows of [Lock order](../../../../docs/architecture/foundation.md#lock-order),
  the answer to a request of [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values).
- money.md: the answer act of [Sponsor and church support](../../../../docs/architecture/money.md#sponsor-and-church-support).

## Invariants

- A registration's only identity is (event ID, reference code): no numeric
  registration ID, and neither part ever changes. Every table of the module
  that holds per-event data is keyed event first.
- Status is a fact: stored, changed only by people's acts through use cases,
  never recomputed. The sorszám is computed when read and never stored.
- The status writers are the submit's insert, the four staff acts of
  `Status/` (`kalaka/admit-registration`, `kalaka/withdraw-registration`,
  `kalaka/remove-registration`, `kalaka/restore-registration`, one table in
  `Api\StatusAct`, one shape in `StatusChange`) and the participant's own
  withdrawal (`kalaka-portal/withdraw-registration`, `WithdrawOwnRegistration`
  [UNAPPROVED N660]); nothing else updates a status. The registration's lock and status
  recheck is written once, in `Status\StatusLock`, which admit, restore and both withdrawals use; a leaving act's
  status and seat release, and the money it would leave, are written once, in `Status\Leaving`, which the staff
  withdraw and remove and the participant's withdrawal all call; there is no second copy.
  An act on a registration already in its target state writes nothing
  (`changed: false`); any other status it does not allow is `StatusActRefused`.
  No act needs a reason; the one given is the audit entry's. A restore is full access only (no grant action) and
  returns the registration to the status it left from: `registered` when it has a `registered_at` (it was ever
  registered), `waitlisted` when it has none, with nothing else written.
- `registered_at` is set only by the acts that make a registration
  `registered` (submit, admission, restore) and is never recomputed.
  `registered_by` (`Api\RegisteredBy`: `submit`, `admission`, `restore`) is
  written only together with it; withdraw and remove leave both as they are.
  `registration.from_waitlist` reads `registered_by = admission`.
- Admission and a restore to `registered` lock the event row, then the quota pools their claims
  name (`Seats::inLockOrder()`), then the registration; the claims come from
  the stored values (`Api\SeatClaims`), read before the locks and again under
  the registration's, and a claim of a quota pool nobody locked is a
  `VersionConflict` (nothing written). They take seats only through
  `Seats::retake()` (claims, the event's places, `AssignmentSeats::retake()`),
  and queue the "registered" mail (admission, caused by the registration; a restore, caused by the registration at
  the version it wrote, so every restore mails). A restore to the waitlist queues the "waitlisted" mail the same way.
  Withdraw and remove queue no mail, free seats only through `Seats::releaseAll()` and revoke the registration's
  manual confirmation flag through `Pricing\Api\ManualConfirmations::revokeOnLeaving()` in the same transaction.
  The preview (`preview: true`) reads with `Seats::wouldOverfill()`, locks and writes nothing and is not audited when it succeeds; it ignores a
  `reason`, and says `requires_reason` (kept in the shape, always false: no act needs a reason). It gives each seat its pool's own `label()`, `taken` (held by others), `taken_after` (the
  count once the act is done: `taken + 1` when it takes the seat, `taken` when it frees it; a leaving act lists the
  pools the registration holds, outcome `released`) and `limit`, the "full" warning, and for withdraw, remove and restore the per-payer money
  effect from the same composition the materializer records (`FeeComposition::of(.., $becoming)`) with
  `money_hidden` (no money-view: `money` is null; false with a null `money`: unpriceable); withdraw and remove add
  the event's `retention` rule; `money` and `retention` are only for a holder of money-view.
- The participant's own withdrawal is `StatusAct::Withdraw` run by the secret-link actor, through the runner
  (`Api\OwnWithdrawal`, which Portal's `Withdrawal` calls after holding the actor to the active registration):
  allowed from `registered` and `waitlisted` only, and only until the event's registration closes (after that it is
  refused as `Api\WithdrawClosed`, `kalaka_withdraw_closed`, and the preview says `allowed: false`; only staff can
  withdraw), no reason, no mail, nobody on the waitlist moves up.
  Its preview (`preview: true`) is `{allowed, paid, owed_after, retained, surplus}` for the registrant's own payer only
  (the `participant` payer of the same composition the staff preview uses; `retained` is the part of `paid` the
  event's retention rule keeps, never more than `paid`, and `surplus` the rest, owed back and never refunded
  automatically), writes nothing and leaves no audit entry; a status that changed under the lock is
  `StatusActRefused`. [UNAPPROVED N660, N662, N666, R345]
- Submissions are append-only: never updated or deleted. Field values hold the
  current state, one row per field, and are what edits change.
- A submit writes field values only through the event's participant-field list
  (`ParticipantFields\Api\ParticipantFields::of()`): every key is an active field of
  the event with the form's type; a `system` field, or one a form may not write,
  is never written. A disagreement fails the submit before anything is written.
- The generator issues only codes that pass the validity rule; a code is never
  reused within its event. A collision is a retryable error; an existing
  registration never gets another code.
- A `RegistrationKey` holds only a code valid in canonical form
  (`ReferenceCode::isValid()`); any other spelling is `InvalidInput`, so none
  reaches a read, a lock or a write.
- A duplicate key is retryable only on the registration insert
  (`insertsReferenceCode()`); every other duplicate key is a `SystemFailure`.
- The reference code grants no access.
- The secret link is a secret: from a CSPRNG, masked in the audit, never in a
  log, an exception's context or a reason.
- The secret is compared in PHP (`hash_equals()`) and never looked up or
  compared in SQL; the link carries the registration key beside it. Only
  Registrations reads the stored secret: another module presents a secret and
  asks whether it matches, or gets the finished link URL for a mail from
  `Api\SecretLinks::url()` (registrations-and-identity.md, "Access": stored
  readable so every email can carry it), and never stores it.
- The active-registration token is `v1.<eventId>.<code>.<issuedAt>.<mac>`,
  issued and verified only by `Api\SecretLinks`: the MAC covers the current
  secret, so a new secret revokes every token issued before it, and a token
  is good for 24 hours from its issue, checked against the clock. The token
  never carries the secret.
- `secret` and `field_key` are `utf8mb4_nopad_bin`: compared exactly.
- Registration rows are locked at `LockLevel::Registration`: after the event
  row and quota rows, before everything else.
- A registration is created only by `kalaka-portal/submit-registration`
  (`SubmitRegistration`, reached through `Api\RegistrationSubmit`): the
  answers are checked, the form's latest version is required and the event's
  participant fields are read before the transaction, then the event row is the
  run's first lock, a submit token the event's submissions already hold is a
  repeat only with the same answers' fingerprint (then it writes nothing and
  the result carries only the after-submit content of the original
  registration) and otherwise `SubmitTokenReused`, the event must still be
  the version the answers were checked against, and the status follows from
  the latch and the count of `registered` registrations read under that lock.
  The latch is set before the registration row is written. Last, in the same
  run, the submit queues the action mail of the resulting status
  (`registered` or `waitlisted`) through `Mail\Api\ActionMails::due()`, with
  the registration as the cause; a repeat queues nothing.
- An additional form's submit is `kalaka-portal/submit-additional-form`
  (`SubmitAdditionalForm`, reached through `Api\RegistrationSubmit` with the
  target registration): the same checks before the transaction and the same
  repeat rule, the event row first, then the form's requirements (its open
  period among them) and the registration row `FOR UPDATE`, which must be
  `registered`: a `waitlisted` one has no additional forms (owner ruling 2026-11-25), so it is refused with
  `Api\RegistrationNotActive` (`kalaka_registration_not_active`) and nothing is written; its form page shows the
  form closed (`AccessRefusal::RegistrationMayNotSubmit`, as for a withdrawn or removed one). It appends a submission with the next number
  and writes only the fields that showed: an answer replaces the value, a
  shown field left blank deletes a value the form showed (one its type
  prefills), and a field its participant field locks after its first write
  (`ParticipantFields::lockedAfterFirstWrite()`) is never changed once
  written (`Api\ValueLocked`, a `RuleViolation` with the code `kalaka_value_locked`). It never writes the registration row, its status or
  `registered_at`, and never changes capacity or the latch (its walk reads
  `event.seats_left` unlocked, before the transaction), and it may take
  seats (owner ruling 2026-11-25), only for a `registered` registration: the registration's values as they would be
  after the submit are asked of every `SeatClaimSource`, and in one run under the pools' locks
  (`SubmitRegistration::lockedClaims()`) the new claims' seats are taken (`SeatAct::Submit`) and the pools no longer
  claimed are freed. A full pool refuses the submit on the choice's field with `SeatsFull::TEXT` as the registration
  form does, nothing is written and the old seat stays; that refusal is said only after the registration is locked
  and the walk's read is checked again (a changed read runs the submit again). A value locked after its first write (D5) still cannot be re-chosen. The
  registration's facts (`RegistrationFacts::formRead()`, built by the one builder `Registrations::factsOf()`)
  and field values are read once per attempt before the
  transaction, for the walk's conditions, and read again under the registration
  lock: if either differs, the use case throws `VersionConflict` and the walk is
  run again. A pool's places left are no part of that read.
- An additional form's submit sends no action mail; the page shows the
  form's after-submit text.
- The submit token is a secret: masked in the audit, never in a log. It is
  compared in SQL: on its own it names an earlier submit, and a repeat reveals
  that submit's after-submit content only with the same answers' fingerprint.
- `Api\RegistrationFacts` is a registration's fact source after submit (the
  one availability base), built only by `Registrations::factsOf()`: the event's values, the code, the status
  (with its word, `RegistrationStatus::word()`, the text a mail shows too), `registration.awaits_answer` and when
  it last became `registered` (`registered_at`, null while it is not), which
  every time-dependent rule counts from, never the submit. It
  holds values only; field values join in S25, over #181's participant-field
  read. A caller may add computed values under dotted keys the source does
  not have (`withComputed()`, e.g. the fee composer's `price_tier.applied`);
  they are never stored.
- The owner account (`owner_user_id`, a WordPress user ID) is set once, by
  the submit of a logged-in person, and nothing changes or clears it. In
  the event's account mode `off` a submit records no owner; in `mandatory`
  mode someone not logged in cannot submit (`account_required`). The owner
  is written only for a new registration, never for a repeat. A submit
  queues no mail of its own for the account.
- `wp-login.php` refuses a cross-site POST of any action on `login_init`
  (`Account\SignUpPage::refuseCrossSite()`: `Sec-Fetch-Site` must be
  `same-origin` or `none`, else `Origin` or `Referer` must be the site's own
  address, none of them lets it through; the one exemption is a form_post
  social-login callback from the provider itself: `loginSocial` names a provider of
  `SignUpPage::FORM_POST_PROVIDERS`, `Origin` (else the `Referer`'s origin) is that provider's
  origin, and the POST carries `code` and `state`), so a page elsewhere cannot sign a visitor in as
  an account of its choosing.
- Accounts are created only on WordPress's own registration page
  (`Account\SignUpPage`), never by a submit; Kaláka never handles a
  password. Its native-sign-up flag is set only on a POST to
  `action=register` without `loginSocial`, never from
  `did_action('login_form_register')`. The flag alone decides the hidden
  login name (`Account\LoginName`: `[a-z0-9._-]`, never empty, at most 60,
  free and not illegal), the opaque nicename, `kalaka_unverified` and the
  login at once; the marker
  (`kalaka_form`, a registration form whose published event has accounts)
  alone decides the suppressed WordPress mails (a sign-up without a valid
  marker, a direct `wp-login.php?action=register`, gets WordPress's own),
  the hidden marker field, the event's line on the login and
  registration pages, and the returns to the form: the login and
  registration links, `registration_redirect` and `login_redirect`, each
  through the return page (`Account\SignUpPage::returnPage()`, on
  `login_form_kalaka_account`) to the form's page found on the server
  (`Forms\Api\SignUp::page()`), never an address from the request. Without
  the popup marker the return page prints no text; it tells only a window opener of its own
  origin, and otherwise goes to the form's page. With the marker
  `popup=1` (`SignUp::POPUP`, exactly `1`; carried through every path
  above, the password-reset login link included) it never goes anywhere:
  it is drawn in WordPress's login frame, tries to close the window, and
  after 300 ms shows a line and a close button if the window is still open. The registration mail's
  set-password link carries the marker, so logging in after the password is
  set returns to the form's page directly.
- `kalaka_unverified` is cleared only on `password_reset` and
  `after_password_reset`; it limits only what Kaláka does with the account.
  A reset link lasts 7 days only for such an account.
- Site-wide, the author archive of any user with no published posts is a
  404 (`SignUpPage`, `pre_handle_404`), so `?author=N` never lists
  participant accounts' names (N21). Authors with posts keep theirs.
- Drafts (`kalaka_drafts`, S74) are keyed event first: a user draft is one
  per event, form and user; a guest draft is found only by the SHA-256 of
  its claim token (`Api\DraftClaim`), which is never stored, logged or
  audited. A guest draft has no expiry of its own: it lives until the form is sent (the submit deletes
  it) or the event's registration has closed (the periodic run's `DraftSweep`; owner ruling 2026-11-26),
  in every account mode. A draft's answers and claim hash are secret columns: the audit
  names the draft, never their values.
- A draft is written only by its use cases: `kalaka-portal/save-draft` (a
  logged-in person's draft, or a guest's guest draft under their cookie's
  token: the first save creates it), `kalaka-portal/hand-over-prefill` (a
  handoff waits as a guest draft under its own token, in the cookie
  `kalaka_handoff`, and never reads or changes the draft the cookie
  `kalaka_draft` names), `kalaka-portal/settle-handoff` (the next load: it
  becomes the browser's draft, settles silently as keep_draft when it changes
  none of a guest's values, or waits for the choice),
  `kalaka-portal/claim-draft`, `kalaka-portal/resolve-draft-claim`,
  `kalaka-portal/resolve-guest-handoff` (a guest's choice, settled on their
  own guest draft; the request proves itself with both tokens, the handoff's
  locked first),
  `kalaka/sweep-drafts`, and the submit, which deletes the person's draft and
  the cookie's guest draft of the form in its own transaction. A draft never
  creates a registration and holds no place.
- A claim that changes what a user draft holds (the guest draft becomes it,
  merge, keep_link), or a guest's own draft (their choice on a handoff:
  merge, keep_link), raises its `claims` count, and nothing else does. An
  autosave carries the count its page opened with; a different count is a
  `VersionConflict`, never a write. A link that changes none of the draft's
  values is settled as keep_draft, with no choice.
- A claim is settled once per claim token. The person's draft (or the guest's
  own, for a handoff) records the
  token's hash and the choice (`resolved_claim`, `resolved_choice`, a secret
  column and its choice); the same choice again changes nothing and answers
  the same, and any other choice, or a claim gone, is refused
  (`DraftClaimRefused`).
- A seat is a fact (`kalaka_seats`, one row per registration and pool), written only through `Api\Seats`,
  and only inside an act that holds the registration's row: the registrant's submit, an additional form's submit
  (a `registered` registration's, which also frees seats), an admission, a restore, staff placing or an answer to an
  offer request. It is taken under a `FOR UPDATE` lock on the pool's own row (`Api\SeatPool::lock()`), taken
  before the seats are counted, so two acts for the last seat serialise and the second counts the first. A pool
  at a lock level before the registration row (a quota row) is locked before the registration row is:
  a submit locks and checks its quota pools before the registration is written, in `Seats::inLockOrder()` (by
  level, then table and key as the runner's guard compares them, never by pool-key string); an act on an
  existing registration (admission, restore, staff placing) calls `Seats::lock()` for such a pool first, then
  locks the registration, then takes the seat. A `take*()` that finds the registration held and a quota row not
  fails the run with the guard's `SystemFailure`: the pool's lock cannot be taken after the registration's. Any
  other pool is locked inside `take*()`, once the registration row is in.
- A full pool refuses `take()` and `kalaka-portal/submit-registration` (the form comes back with the refusal on
  the choice's field, nothing saved). Admission, restore and staff placing take the seat with `retake()`
  (`takeOver()` is private to `Seats`): over the limit when the pool is full, with the "full" warning; only a
  submit is refused by a full pool. `retake()` is the one entry for admission, restore and staff placing: per
  pool it reports `held`, `over` or `none` and never refuses, and never touches the event's "full" latch. A staff
  act reads the "full" warning from that outcome; the preview before the act reads `wouldOverfill()`. Only a
  `registered` submit takes its claims' seats: a waitlisted one holds none, and is refused when any claim is a pool
  with a limit (see below).
- A seat is freed by leaving (`release()`, `releaseAll()` on withdrawal and removal, and later an answer that
  refuses an offer) or by the registration's own additional form no longer claiming its pool (`SeatMove`). A freed
  seat stays free until some registration's own act takes it: nothing here moves a seat from one registration to
  another, and a count never hands a place on.
- A submit's seat claims come from contributed `Api\SeatClaimSource`s
  (`Contributions::seatClaimSource()`): answers in, `SeatClaim`s out; a source locks, counts and writes nothing.
  `RegistrationSubmit` asks each on every attempt (for an additional form, as the `SeatMove` of its values before
  and after) and hands the claims to the use case. `pools()` names every pool
  a source can claim (field key, option value, pool), for the form host's `seats_left.<field key>.<option value>`.
  `Seats::wouldOverfill()` is the one "would go over" read; `Seats::left()` of the event's pool is 0 while its
  latch is set.
  A source claims only the registration's current entitlement: a choice that gives it no place now (an offer
  whose request was refused) makes no claim, for a submit's answers and for stored values alike.
- A submit that lands on the waitlist may choose nothing limited (owner ruling 2026-11-25, O4/A3): any claim
  on a pool with a limit is refused on that choice with the same refusal and text as a full pool
  (`SeatsFull::TEXT`; the field's own error, as a pool that filled up) and nothing is written; unlimited pools
  are not refused. "Goes to the waitlist" is the one read `Seats::left(eventPool) === 0`, under the event lock,
  the same read the form side uses. Admitted registrations' additional forms are unchanged.
- The event's pool has no rows: its seats are the counted `registered` statuses, and the latch decides the
  waitlist as it always did. `Seats::left()` and `over()` are computed on read, never stored.
- Removing a chosen item (a tier, an option, an offer) is refused through one call,
  `Registrations::refuseRemovingChosen()`, which throws `Api\ItemChosen` (`kalaka_item_chosen`); a module's own
  removal guard calls it and does not throw its own refusal.
- A live registration is a registered one (owner 2026-11-27): code that means live calls `RegistrationStatus::isLive()`
  (SQL: `RegistrationTables::isLive()`), never compares with `Registered`. Waitlisted, withdrawn and removed are
  not live ("active registration" stays the registrant's access). Code that means the status itself (a
  transition's target, `registered_at`, the sorszám's groups) names it.
- `RegistrationStatus::showsDetails()` (true unless waitlisted) is the one read of what a waitlisted registrant
  sees (the registrant's page shows only that they are waitlisted: owner ruling 2026-11-25). It is not an action
  gate: additional forms and payments stay on `isLive()`.
- A staff correction of a field value (`Api\FieldCorrections`, used by Admin's `EditFieldValue`) writes the value
  only through `FieldValueRepository` and moves seats only through the claim sources: the seats the old value
  claimed and the new one does not are freed, the others taken, never through per-field code. The pools to take are
  planned from a plain read and locked (`lockPools()`) before the registration row is, and the plan is checked
  against the values re-read under the lock (else `VersionConflict`). Seats move only for a `registered`
  registration, and the take is `Seats::retake()` (over the limit when the pool is full, reported as `over`; the
  preview reads `wouldOverfill()`). The class returns no stored value (it answers booleans, option keys and field
  keys, and compares the registration's state against a fingerprint inside, and only in `check()`, which takes
  the run's `Context` and refuses a run that does not hold the registration), so Admin, which may not use the
  "Registration data" layer, reaches registration data only through the view query still.

- Requests and their answers (money.md, "Sponsor and church support"; foundation.md, "Facts, decisions and
  computed values"; slice B). A request is named by a `RequestSource` a module contributes (an inside point); core
  ships none, so with no source every read is empty and costs no statement. Each `Request` a source builds names its
  participant fields as stored keys: `askFields` (what the participant asks with) and `answerField` (where its
  answer is written), which the admin detail groups by.
  - The one act that answers a request is `AnswerRequest` (`kalaka/answer-request`, action
    `kalaka_answer_request`), for offers and aid alike, used by staff and by a partner (a grant holder whose view
    holds only their registrations); the runner checks the target against the view. The one action gates every
    request kind: a holder may answer any offer and award aid, up to the full price, on the registrations in the view.
  - An answer is a fact with two writes in one act: one `kalaka_answers` row per answering act (append-only,
    never an upsert: its id is the key the "answered" mail takes, `AnswerRead::subject()`; it records who, when,
    the note), and the answer as the source's own participant field value, written by the source
    (`RequestSource::writeAnswer()`, called by the act) through `Registrations::writeValue()`, so the source
    stays that field's only writer. `writeValue()` takes the writing module's declarer (`aid`,
    `support`): one writer rule, a field is written by its declarer's own code only (it refuses a field
    whose `declared_by` is another declarer). It refuses a run that does not hold
    the registration, a key the event does not declare and a "system" field.
  - The latest row per request is the act's record, not the answer. An identical repeat (same yes or no, same
    amount, no new note) writes nothing; an answer whose only change is a new note is a new act. Aid "no" is an
    award of 0 Ft: an absent answer awaits, a zero is an answer. The note, a "yes" to an offer's
    reason included, is always optional.
  - "Awaits an answer" is computed, never stored (`Requests::awaits()`): a request whose source says it is not
    answered (`RequestSource::isAnswered()`, read from the source's own answer field: an offer's `<key>_answer`, the
    aid award, empty = awaiting, "0" = refused), for a registration that is `registered`. The field is the truth: the
    `kalaka_answers` rows record the act (who, when, the note, `lastAnsweredAt`, the mail key) and decide nothing
    else; a request re-stated after an answer reads as answered until staff answer again. It is
    `registration.awaits_answer` in the fact source, and it hides the due date and the transfer block in Portal. It
    never blocks confirmation.
  - The admin grid asks the same question in SQL (`Requests::awaitingCondition()`, no statement of its own): the
    registration is `registered` and some source's `RequestSource::awaitingRule()` holds. The rule is data (an
    `AwaitingRule`: any-of groups of all-of `FieldTest`s: filled, empty, one of, none of, over the registration's
    field values); core alone compiles it to SQL (no source writes SQL or gets database access) and `awaits()`
    evaluates the same rule in PHP with `matches()`, and it must mean exactly what the source's `requestsOf()` and
    `isAnswered()` mean (the participant field is the truth: an empty answer field awaits). It has three values
    (owner 2026-11-27: N/A is different from „Nem”): yes or no for a `registered` registration, and N/A for every
    other status, NULL in SQL, so a waitlisted registration is neither; `awaits()` (the rule in PHP) returns null
    for every status but `registered` too, one meaning for the fact, the column and the panel.
  - Only a `registered` registration is answered: a waitlisted registration is inactive, so its request waits until
    admission (the answer act refuses a waitlisted, withdrawn or removed one with `RuleViolation`); `awaits_answer`
    is N/A for it (neither yes nor no) and yes again by itself at admission. A refusing offer answer frees the
    offer's seat and a "yes" after a refusal takes one again. Both use slice A's one seat rule, `Seats`: the request
    source builds its pool (`RequestSource::seatPool(RegistrationKey, Request): ?SeatPool`; the key is passed so a
    source whose pools belong to an event can find the pool; null, as for aid, means the source takes no seat for the
    request and there is no seat call; an offer with no quota still has a pool, with no limit), so core never parses
    a pool key, and the act locks it before the registration lock. A full pool never
    refuses the "yes": `retake()` answering `SeatOutcome::Over` is `over_limit`, and the detail read's `seat_full` is
    `Seats::wouldOverfill()`.
  - An aid "yes" above the full price the participant has to pay is refused with `AidAbovePrice` (a
    `RuleViolation`, code `kalaka_aid_over_price`) and nothing is written. The price comes from the request source's
    `RequestSource::terms()` (`RequestTerms`: `fullPrice`, `canPay`; the most an award can be is `maxAmount()`, the
    full price today); a source with no terms (an offer) sets no cap. The detail read shows the same terms.
  - The act writes no ledger line or decision; its one mail is "answered", queued in the act's own transaction
    through `Mail\Api\ActionMails::due()`, keyed by the answer record (`AnswerRead::subject()`), so every
    answering act mails once and a repeat that writes nothing queues none. The registration is touched, so the
    evaluation (money, then confirmation, then mail) follows in the same run.
- Only Registrations writes its tables.
