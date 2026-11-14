# Ledger

Lines, payers, balances, organizer-recorded payments and refunds, and moving payments.

At the module root: `LedgerLineRepository` (the only writer of `kalaka_ledger_lines`, append-only, keyed by ID and read per registration), `BalanceQuery` (the balances of a registration, computed from its lines), `PaymentFactQuery` (its payment-fact lines) and `SummaryQuery` (each payer's charged, paid and balance and its other lines, for display).

`Api/` holds `Ledger` (the contract: `record()`, `balancesOf()`, `paymentFactsOf()`, `summaryOf()`), `Summary`, `PayerSummary`, `ChargeLine`, `NewLine`, `LedgerLine`, `LineKind`, `Payer`, `Balances` (`of(payer, currency)` and `total(currency)`, zero in the event's currency without lines) and `PaymentFact`.

Tables, created by the schema in `Module.php`: `kalaka_ledger_lines`.

Entry points: none yet.
