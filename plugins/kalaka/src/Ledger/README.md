# Ledger

Lines, payers, balances, organizer-recorded payments and refunds, and moving payments.

At the module root: `LedgerLineRepository` (the only writer of `kalaka_ledger_lines`, append-only, keyed by ID and read per registration), `BalanceQuery` (the balances of a registration, computed from its lines) and `PaymentFactQuery` (its payment-fact lines).

`Api/` holds `Ledger` (the contract: `record()`, `balancesOf()`, `paymentFactsOf()`), `NewLine`, `LedgerLine`, `LineKind`, `Payer`, `Balances` and `PaymentFact`.

Tables, created by the schema in `Module.php`: `kalaka_ledger_lines`.

Entry points: none yet.
