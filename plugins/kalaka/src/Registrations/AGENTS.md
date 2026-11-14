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
  the secret links of [Access](../../../../docs/architecture/registrations-and-identity.md#access).
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
  (`Forms\Api\Forms::participantFields()`): every key is an active field of
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
  asks whether it matches.
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
  The latch is set before the registration row is written.
- The submit token is a secret: masked in the audit, never in a log. It is
  compared in SQL: on its own it names an earlier submit, and a repeat reveals
  that submit's after-submit content only with the same answers' fingerprint.
- `Api\RegistrationFacts` is a registration's fact source after submit (the
  one availability base): the event's values, the code, the status and when
  it last became `registered` (`registered_at`, null while it is not), which
  every time-dependent rule counts from, never the submit. It
  holds values only; field values join in S25, over #181's participant-field
  read.
- Only Registrations writes its tables.
