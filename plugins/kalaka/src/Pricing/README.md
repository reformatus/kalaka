# Pricing

The fixed price, the fee-component framework, the materializer and the money settings apply. Confirmation (S20) and retention (S23) come later.

At the module root: `FixedPrice` (core's fee component, at the tier stage), `ManualFees` (core's `manual` component, at the extras stage: one item, so one `charge` line, per manual charge or discount of the Ledger; it names its lines „Kézi díj” and „Kézi kedvezmény”), `FixedPriceRepository` and `FixedPriceRecord` (the only writer of `kalaka_fixed_prices`), `FeeComposer` (composes the components stage by stage; shared by the materializer and the preview), `CoreFeeComponents` (the components registered now), and the use case `SetFixedPrice` (`kalaka/set-fixed-price`). An apply re-evaluates each registration through Foundation's `Api\Evaluation\Evaluations`.

`Api/` holds the fee-component contract (`FeeComponent`, `FeeStage`, `FeeComponentInput`, `FeeTargets` (one line per payer, or per item and payer), `NamedFeeComponent`, `LineName`, `LineNames` (how the admin names an owed line), `StageTotals`, `FeeComponentFailed`), `Materializer` (the evaluation's money phase) and `MoneySettings` (`preview()`, `apply()`, `retry()`, with `ProposedMoneySettings`, `PreviewRow`, `ApplyReport` and `ApplyItem`).

Tables, created by the schema in `Module.php`: `kalaka_fixed_prices`. There is no tariff snapshot.

Entry points: none yet (the settings screen is S72's).
