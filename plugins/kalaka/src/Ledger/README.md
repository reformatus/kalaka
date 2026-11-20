# Ledger

Lines, payers, balances, organizer-recorded payments and refunds, and moving payments.

At the module root: `LedgerLineRepository` (the only writer of `kalaka_ledger_lines`, append-only, keyed by ID and read per registration), `PaymentNoteRepository` (the only writer of `kalaka_payment_notes`, the free text of a manual line, which erasure reaches), `BalanceQuery` (the balances of a registration, computed from its lines), `PaymentFactQuery` (its payment-fact lines), `SummaryQuery` (each payer's charged, paid and balance and its other lines, for display), `LineListQuery` (its lines newest first, each with its note, for display) and `ChargeTotalQuery` (its `charge` lines summed per source and payer, for the materializer only).

`Api/` holds `Ledger` (the contract: `record()`, `entriesOf()`, `eraseNotesOf()`, `balancesOf()`, `paymentFactsOf()`, `summaryOf()`, and `chargeTotalsOf()` for Pricing's materializer only, plus the per-event action names `VIEW_MONEY`, `RECORD_PAYMENT` and `RECORD_REFUND`), `Summary`, `PayerSummary`, `ChargeLine`, `ChargeTotal`, `NewLine`, `RecordedLine` (what `record()` returns: the line and whether this call inserted it), `LedgerLine`, `LedgerEntry`, `LineKind`, `Payer`, `PayerKind`, `PayerKinds` (the payer kinds that apply to an event, the one source for who may pay), `Balances`, `PaymentFact`, `PaymentMethod` and `IdempotencyConflict`.

Use cases: `RecordPayment`, `RecordRefund` and `RecordCorrection`, the manual entries (all `RecordManualEntry`: one line and its note, under the registration's lock). A correction (the owner's ruling 2026-11-19) is a `manual_payment` (`direction` `in`) or `manual_refund` (`out`) flagged `correction`, with a required reason, no method and no value date: it counts from the time it is recorded, and as a payment fact like any recorded payment; it needs `kalaka_record_payment`.

A replay with the same idempotency key and the same line is no conflict, and neither is one after the line's note was erased (`eraseNotesOf()`). Since a line keeps no mark of an erased note, a replay that adds a note to a line recorded without one is accepted too, and the new note is dropped.

Tables, created by the schema in `Module.php`: `kalaka_ledger_lines`, `kalaka_payment_notes`.

Entry points: the abilities `kalaka/record-payment`, `kalaka/record-refund` and `kalaka/record-correction`.
