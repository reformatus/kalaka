# Pricing

The fixed price, the fee-component framework, the materializer and the money settings apply. Confirmation (S20) and retention (S23) come later.

At the module root: `FixedPrice` (core's fee component, at the tier stage), `FixedPriceRepository` and `FixedPriceRecord` (the only writer of `kalaka_fixed_prices`), `FeeComposer` (composes the components stage by stage; shared by the materializer and the preview), `CoreFeeComponents` (the components registered now), and the use case `SetFixedPrice` (`kalaka/set-fixed-price`). An apply re-evaluates each registration through Foundation's `Api\Evaluation\Evaluations`.

`Api/` holds the fee-component contract (`FeeComponent`, `FeeStage`, `FeeComponentInput`, `FeeTargets` with its computed
values for the later stages, `StageTotals`, `FeeComponentFailed`, and `FeeRefusal` and `FeeRefused` for a component's
refusal that says why), `Materializer` (the evaluation's money phase) and `MoneySettings` (`preview()`, `apply()`,
`retry()`, with `ProposedMoneySettings`, `PreviewRow`, `ApplyReport` and `ApplyItem`).

Tables, created by the schema in `Module.php`: `kalaka_fixed_prices`. There is no tariff snapshot.

Entry points: the WP-CLI command `wp kalaka money-settings show|preview|apply <event_id>` (`Cli/MoneySettingsCommand`; `Cli/SettingsDocument` is the JSON shape: `fixed_price` and `components` keyed by component ID, each component a `Api\ConfigurableFeeComponent`). The settings screen is S72's.

Modules contribute fee components with `Contributions::feeComponent()`; `CoreFeeComponents` lists core's fixed
price, then the contributed ones, core's Extras among them. A module's proposed settings reach `MoneySettings` as
`Api\ProposedComponentSettings` (its component for the preview, and the `Api\SettingsSave` use case the apply runs
first). The fixed price is the base only without a ladder: `FeeComposer` skips it when a component provides the
event's base (`Api\ProvidesBase`).

A component that owes several things apart (Extras: one item per charge) returns `FeeTargets::ofItems()`; each item is
its own source `<component ID>:<item>` in the ledger, and the materializer reverses a recorded item the component
no longer returns.
