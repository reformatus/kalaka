# Pricing

The fixed price, the fee-component framework, the materializer, the money settings apply and confirmation with the manual flag. Retention (S23) comes later.

At the module root: `FixedPrice` (core's fee component, at the tier stage), `FixedPriceRepository` and `FixedPriceRecord` (the only writer of `kalaka_fixed_prices`), `FeeComposer` (composes the components stage by stage; shared by the materializer and the preview), `CoreFeeComponents` (the components registered now), the use case `SetFixedPrice` (`kalaka/set-fixed-price`), `FullAmountThreshold` (core's confirmation threshold), `ManualConfirmationRecord` and `ManualConfirmationRepository` (the only writer of `kalaka_manual_confirmations`), and the use cases `SetManualConfirmation` (`kalaka/set-manual-confirmation`) and `RevokeManualConfirmation` (`kalaka/revoke-manual-confirmation`), over `ManualConfirmationChange`. An apply re-evaluates each registration through Foundation's `Api\Evaluation\Evaluations`.

`Api/` holds the fee-component contract (`FeeComponent`, `FeeStage`, `FeeComponentInput`, `FeeTargets`, `StageTotals`, `FeeComponentFailed`), `FeeComposition` and `ComposedFee` (the one read-only composition), `Materializer` (the evaluation's money phase), `ConfirmationRule` (core's confirmation decision, which Foundation's boot registers), the threshold point (`ConfirmationThreshold`, `ConfirmationThresholdInput`), `ManualConfirmations` (the action name and the flag's read, as `ManualConfirmation`) and `MoneySettings` (`preview()`, `apply()`, `retry()`, with `ProposedMoneySettings`, `PreviewRow`, `ApplyReport` and `ApplyItem`).

Tables, created by the schema in `Module.php`: `kalaka_fixed_prices`, `kalaka_manual_confirmations`. There is no tariff snapshot.

Entry points: the abilities `kalaka/set-manual-confirmation` and `kalaka/revoke-manual-confirmation`; the settings screen is S72's.
