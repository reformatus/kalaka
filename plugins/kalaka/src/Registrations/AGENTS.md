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
- foundation.md: the registration rows of [Lock order](../../../../docs/architecture/foundation.md#lock-order).

## Invariants

- A registration's only identity is (event ID, reference code): no numeric
  registration ID, and neither part ever changes. Every table of the module
  that holds per-event data is keyed event first.
- Status is a fact: stored, changed only by people's acts through use cases,
  never recomputed. The sorszám is computed when read and never stored.
- `registered_at` is set only by the acts that make a registration
  `registered` (submit, admission, restore) and is never recomputed.
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
- The submit token is a secret: masked in the audit, never in a log. It is
  compared in SQL: on its own it names an earlier submit, and a repeat reveals
  that submit's after-submit content only with the same answers' fingerprint.
- `Api\RegistrationFacts` is a registration's fact source after submit (the
  one availability base): the event's values, the code, the status and when
  it last became `registered` (`registered_at`, null while it is not), which
  every time-dependent rule counts from, never the submit. It
  holds values only; field values join in S25, over #181's participant-field
  read.
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
  (`Forms\Api\SignUp::page()`), never an address from the request. The
  return page prints no text; it tells only a window opener of its own
  origin, and otherwise goes to the form's page. The registration mail's
  set-password link carries the marker, so logging in after the password is
  set returns to the form's page directly.
- `kalaka_unverified` is cleared only on `password_reset` and
  `after_password_reset`; it limits only what Kaláka does with the account.
  A reset link lasts 7 days only for such an account.
- Site-wide, the author archive of any user with no published posts is a
  404 (`SignUpPage`, `pre_handle_404`), so `?author=N` never lists
  participant accounts' names (N21). Authors with posts keep theirs.
- A seat is a fact (`kalaka_seats`, one row per registration and pool), written only through `Api\Seats`,
  and only inside an act that holds the registration's row: the registrant's submit, an admission, a restore or
  staff placing. It is taken under a `FOR UPDATE` lock on the pool's own row (`Api\SeatPool::lock()`), taken
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
  `registered` submit takes its claims' seats: a waitlisted one holds none and is not refused.
  [UNAPPROVED R40, R100]
- A seat is freed only by leaving (`release()`, `releaseAll()` on withdrawal and removal, and later an answer that
  refuses an offer). A freed seat stays free until some registration's own act takes it: nothing here moves a seat
  from one registration to another, and a count never hands a place on.
- A submit's seat claims come from contributed `Api\SeatClaimSource`s
  (`Contributions::seatClaimSource()`): answers in, `SeatClaim`s out; a source locks, counts and writes nothing.
  `RegistrationSubmit` asks each on every attempt and hands the claims to the use case. `pools()` names every pool
  a source can claim (field key, option value, pool), for the form host's `seats_left.<field key>.<option value>`.
  `Seats::wouldOverfill()` is the one "would go over" read; `Seats::left()` of the event's pool is 0 while its
  latch is set.
  A source claims only the registration's current entitlement: a choice that gives it no place now (an offer
  whose request was refused) makes no claim, for a submit's answers and for stored values alike
  [UNAPPROVED R6].
- The event's pool has no rows: its seats are the counted `registered` statuses, and the latch decides the
  waitlist as it always did. `Seats::left()` and `over()` are computed on read, never stored.
- Removing a chosen item (a tier, an option, an offer) is refused through one call,
  `Registrations::refuseRemovingChosen()`, which throws `Api\ItemChosen` (`kalaka_item_chosen`); a module's own
  removal guard calls it and does not throw its own refusal. [UNAPPROVED R101]
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
- Only Registrations writes its tables.
