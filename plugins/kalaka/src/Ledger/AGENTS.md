# Ledger

Ledger is lines, payers, balances, organizer-recorded payments and refunds,
and moving payments
([Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules)).

## Sections it implements

- money.md: [Ledger](../../../../docs/architecture/money.md#ledger),
  the stored form of [Amounts](../../../../docs/architecture/money.md#amounts),
  the payment facts of [Materializer](../../../../docs/architecture/money.md#materializer)
  ("read from payment lines only").
- foundation.md: the balance of
  [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (computed, never stored); the append-only records of
  [Entities, repositories and queries](../../../../docs/architecture/foundation.md#entities-repositories-and-queries);
  the Time and Money rows of [Cross-cutting rules](../../../../docs/architecture/foundation.md#cross-cutting-rules).
- registrations-and-identity.md: the ledger lines of [Attribution and erasure](../../../../docs/architecture/registrations-and-identity.md#attribution-and-erasure).

## Invariants

- The ledger is append-only: a line is inserted once and never updated or
  deleted; every change is a new line with a source reference and an
  idempotency key.
- One ledger per registration: every line belongs to one registration, keyed
  event first, and has a payer; the participant is the default payer.
- A positive amount increases what the payer owes, a negative one decreases
  it; no line is exactly zero. Amounts are `Money`, stored as
  `DECIMAL(26,8)` with their currency.
- An idempotency key is 1–128 characters of `[a-z0-9_:.-]` (lower case, so the
  collation cannot merge two keys) and is recorded once per registration:
  presenting a stored key with the same line writes nothing; with a different
  line it is an error. A duplicate key on a line is never retried (only the
  registration insert's is); a writer that can race locks the registration
  row first.
- Balances and totals are computed from the lines and never stored.
- Payment facts are the lines of kinds `payment`, `refund`, `manual_payment`,
  `manual_refund` and `transfer`; `charge` and `adjustment` lines are not
  payment facts. Rules never read charge lines: the Ledger `Api` gives them
  payment facts, read from payment lines only.
- A payment-fact line carries exactly one time: `paid_at`, a UTC instant, on
  `payment` and `refund`; `value_date` on `manual_payment`, `manual_refund`
  and `transfer`. A `charge` or `adjustment` carries neither.
- Other modules write and read lines only through `Ledger\Api\Ledger`; only
  Ledger writes its tables.
- `record()` refuses unless the run holds the registration's lock
  (`Context::holdsRegistration()`).
- Ledger lines survive erasure and are kept at least 8 years, so a line holds
  no free text and no personal data: a bank reference, payer note or reason
  lives where erasure can reach it.
