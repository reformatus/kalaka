# Deposits

Deposits is one extension plugin, `kalaka-deposits` (module `deposits`), that uses only core's
public API ([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
It stores the per-event deposit amount; the threshold, the advance and the retention rule that read it are
the money side's (money.md, [Confirmation](../../../../docs/architecture/money.md#confirmation) and
[Withdrawal, removal and substitution](../../../../docs/architecture/money.md#withdrawal-removal-and-substitution)).

## Sections it implements

- money.md: [Confirmation](../../../../docs/architecture/money.md#confirmation) and
  [Withdrawal, removal and substitution](../../../../docs/architecture/money.md#withdrawal-removal-and-substitution)
  (the deposit extension's seam: the threshold, the advance and "retain up to the deposit").
- money.md: [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply)
  (the deposit is a component of the money settings: preview → confirm → apply, and the apply re-evaluates).
- modules-and-extensions.md:
  [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Extensions in view](../../../../docs/architecture/modules-and-extensions.md#extensions-in-view),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- This plugin stores only the per-event deposit amount: one row in `kalaka_deposit_settings`, keyed by the
  event, `amount` decimal text in the event's currency. No row means no deposit. Only this module writes the
  table, `DepositSettingsRepository` being its only writer.
- The deposit changes only through `kalaka/set-deposit`, the save step of a money settings apply
  (`Api\Deposits::propose()` with `Pricing\Api\MoneySettings`): preview, then apply. Turning it off deletes
  the row only.
- The component `deposit` is a `Pricing\Api\ConfigurableFeeComponent`: the money settings file of
  `wp kalaka money-settings` reads and writes it through `settingsToArray()` and `settingsFromArray()`, which
  only call `Api\Deposits`. It composes last (the extras stage) and writes no fee line: the threshold, the
  advance and the retention rule are the money side's (a later slice).
- The amount is a positive decimal text with at most two decimals; `0`, `0.00` and a negative are refused
  (N829). It is not turned into money here; the money side converts it with the event's currency.
- No screen, route or participant text. The command line is `wp kalaka money-settings`, core's.
