# Ledger

Lines, payers, balances, organizer-recorded payments and refunds, and moving payments.

At the module root: `LedgerLineRepository` (the only writer of `kalaka_ledger_lines`, append-only, keyed by ID and read per registration), `BalanceQuery` (the balances of a registration, computed from its lines) `PaymentFactQuery` (its payment-fact lines) and `ChargeTotalQuery` (its `charge` lines summed per source and payer, for the materializer only).

`Api/` holds `Ledger` (the contract: `record()`, `balancesOf()`, `paymentFactsOf()`, and `chargeTotalsOf()` for Pricing's materializer only), `ChargeTotal`, `NewLine`, `LedgerLine`, `LineKind`, `Payer`, `Balances` (`of(payer, currency)` and `total(currency)`, zero in the event's currency without lines) and `PaymentFact`.

Tables, created by the schema in `Module.php`: `kalaka_ledger_lines`.

Entry points: none yet.
