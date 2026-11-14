# Pricing

The fixed price, the fee-component framework, the materializer and the money settings apply. Confirmation (S20) and retention (S23) come later.

At the module root: `FixedPrice` (core's fee component, at the tier stage, invoiceable), `FixedPriceRepository` and `FixedPriceRecord` (the only writer of `kalaka_fixed_prices`), `FeeComposer` (composes the components stage by stage; shared by the materializer and the preview), `CoreFeeComponents` (the components registered now), and the use cases `SetFixedPrice` (`kalaka/set-fixed-price`) and `ApplyMoneySettings` (`kalaka/apply-money-settings`, one registration of an apply).

`Api/` holds the fee-component contract (`FeeComponent`, `FeeStage`, `FeeComponentInput`, `FeeTargets`, `StageTotals`, `FeeComponentFailed`), `Materializer` (the evaluation's money phase) and `MoneySettings` (`preview()`, `apply()`, `retry()`, with `ProposedMoneySettings`, `PreviewRow`, `ApplyReport` and `ApplyItem`).

Tables, created by the schema in `Module.php`: `kalaka_fixed_prices`. There is no tariff snapshot.

Entry points: none yet (the settings screen is S72's).
