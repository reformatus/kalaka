# Kaláka – Foglalók

The deposits extension plugin (`kalaka-deposits`, module `deposits`): it stores the deposit amount of
each event for the money side's confirmation threshold, advance and retention rule, and carries it in the
money settings. It uses only core's public API
([Packaging](../../docs/architecture/modules-and-extensions.md#packaging)).

Table, created by the schema in `Module.php`: `kalaka_deposit_settings` (event, amount as decimal text in
the event's currency); only `DepositSettingsRepository` writes it. An event without a row has no deposit.

At the module root: `DepositComponent` (the fee component `deposit`, contributed in `Module::contribute()`),
`DepositSettings` and `DepositSettingsRepository`, `DepositInput` (the amount's checks) and the admin use
case `SetDeposit` (`kalaka/set-deposit`; full access only). `Api/` holds `Deposits` (`amount()`, and
`propose()`, which gives a `ProposedDeposit` for `Pricing\Api\MoneySettings::preview()` and `apply()`).

Entry points: none. The money settings file carries it as `"deposit": {"amount": "10000"}` (`{"amount": null}`
turns it off), so `wp kalaka money-settings show`, `preview` and `apply` read, preview and save it, and the
apply re-evaluates every open registration. The amount is greater than zero, with at most two decimals (N829).
The component writes no fee line: the threshold, the advance and the retention rule are the money side's.

There is no screen, route or participant text. The code is in `src/Deposits/` (see its `AGENTS.md`); tests
are in `tests/integration/`.
