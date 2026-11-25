# Pricing

The fixed price, the fee-component framework, the materializer, the money settings apply and confirmation with the manual flag. Retention (`Api\RetentionRule` and `Api\RetentionRules`, `SetRetention`, `kalaka_retention`): what the event keeps of a leaving registration's payments, per payer, by a rule chosen per event (core: "everything paid" by default, or nothing; an extension may contribute more).

At the module root: `FixedPrice` (core's fee component, at the tier stage), `ManualFees` (core's `manual` component, at the extras stage: one item, so one `charge` line, per manual charge or discount of the Ledger; it names its lines „Kézi díj” and „Kézi kedvezmény”), `FixedPriceRepository` and `FixedPriceRecord` (the only writer of `kalaka_fixed_prices`), `FeeComposer` (composes the components stage by stage; shared by the materializer and the preview), `CoreFeeComponents` (the components registered now), the use case `SetFixedPrice` (`kalaka/set-fixed-price`), `FullAmountThreshold` (core's confirmation threshold), `ManualConfirmationRecord` and `ManualConfirmationRepository` (the only writer of `kalaka_manual_confirmations`), `UnconfirmationRecord` and `UnconfirmationRepository` (the only writer of `kalaka_unconfirmations`), and the use cases `SetManualConfirmation` (`kalaka/set-manual-confirmation`) and `Unconfirm` (`kalaka/unconfirm`, staff's unconfirm, which revokes the flag; confirmation then decides again at once), over `ManualConfirmationChange`. An apply re-evaluates each registration through Foundation's `Api\Evaluation\Evaluations`.

`Api/` holds the fee-component contract (`FeeComponent`, `FeeStage`, `FeeComponentInput`, `FeeTargets` (one line per payer, or per item and payer, with its computed values for the later stages), `NamedFeeComponent`, `LineName`, `LineNames` (how the admin and the participant's money table name an owed line, with its component's stage), `StageTotals`, `FeeComponentFailed`, and `FeeRefusal` and `FeeRefused` for a component's refusal that says why), `FeeComposition` and `ComposedFee` (the one read-only composition), `Materializer` (the evaluation's money phase), `ConfirmationRule` (core's confirmation decision, which Foundation's boot registers), the threshold point (`ConfirmationThreshold`, `ConfirmationThresholdInput`), `Confirmations` (`isConfirmed()` and the batched `confirmedOf()`: the one read of whether a registration is confirmed), `ManualConfirmations` (the action name, the flag's read, as `ManualConfirmation`, and `reconfirmsOnUnconfirm()`, the dry run that warns whether an unconfirm would confirm again at once) and `MoneySettings` (`preview()`, `apply()`, `retry()`, with `ProposedMoneySettings`, `PreviewRow`, `ApplyReport` and `ApplyItem`).

Tables, created by the schema in `Module.php`: `kalaka_fixed_prices`, `kalaka_manual_confirmations`, `kalaka_unconfirmations`. There is no tariff snapshot.

Entry points: the WP-CLI command `wp kalaka money-settings show|preview|apply <event_id>` (`Cli/MoneySettingsCommand`; `Cli/SettingsDocument` is the JSON shape: `fixed_price` and `components` keyed by component ID, each component a `Api\ConfigurableFeeComponent`). The settings screen is S72's.

Modules contribute fee components with `Contributions::feeComponent()`; `CoreFeeComponents` lists core's fixed
price and manual fees, then the contributed ones, core's Extras among them. A module's proposed settings reach `MoneySettings` as
`Api\ProposedComponentSettings` (its component for the preview, and the `Api\SettingsSave` use case the apply runs
first). The fixed price is the base only without a ladder: `FeeComposer` skips it when a component provides the
event's base (`Api\ProvidesBase`).

A component that owes several things apart (Extras: one item per charge) returns `FeeTargets::ofItems()`; each item is
its own source `<component ID>:<item>` in the ledger, and the materializer reverses a recorded item the component
no longer returns.
The abilities `kalaka/set-manual-confirmation` and `kalaka/unconfirm` are entry points too.
