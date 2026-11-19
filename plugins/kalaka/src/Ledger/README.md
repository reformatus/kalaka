# Ledger

Lines, payers, balances, organizer-recorded payments and refunds, and moving payments.

At the module root: `LedgerLineRepository` (the only writer of `kalaka_ledger_lines`, append-only, keyed by ID and read per registration), `PaymentNoteRepository` (the only writer of `kalaka_payment_notes`, the free text of a manual line, which erasure reaches), `BalanceQuery` (the balances of a registration, computed from its lines), `PaymentFactQuery` (its payment-fact lines), `SummaryQuery` (each payer's charged, paid and balance and its other lines, for display), `LineListQuery` (its lines newest first, each with its note, for display) and `ChargeTotalQuery` (its `charge` lines summed per source and payer, for the materializer only).

`Api/` holds `Ledger` (the contract: `record()`, `entriesOf()`, `eraseNotesOf()`, `balancesOf()`, `paymentFactsOf()`, `summaryOf()`, and `chargeTotalsOf()` for Pricing's materializer only, plus the per-event action names `VIEW_MONEY`, `RECORD_PAYMENT` and `RECORD_REFUND`), `Summary`, `PayerSummary`, `ChargeLine`, `ChargeTotal`, `NewLine`, `RecordedLine` (what `record()` returns: the line and whether this call inserted it), `LedgerLine`, `LedgerEntry`, `LineKind`, `Payer`, `PayerKind`, `PayerKinds` (the payer kinds that apply to an event, the one source for who may pay), `Balances`, `PaymentFact`, `PaymentMethod` and `IdempotencyConflict`.

Use cases: `RecordPayment` and `RecordRefund`, the two manual entries (both `RecordManualEntry`: one line and its note, under the registration's lock).

A replay with the same idempotency key and the same line is no conflict, and neither is one after the line's note was erased (`eraseNotesOf()`). Since a line keeps no mark of an erased note, a replay that adds a note to a line recorded without one is accepted too, and the new note is dropped.

Tables, created by the schema in `Module.php`: `kalaka_ledger_lines`, `kalaka_payment_notes`.

Entry points: the abilities `kalaka/record-payment` and `kalaka/record-refund`.
