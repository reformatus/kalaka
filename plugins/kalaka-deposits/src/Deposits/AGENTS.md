# Deposits

Deposits is one extension plugin, `kalaka-deposits` (module `deposits`), that uses only core's
public API ([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).

## Sections it implements

- money.md: [Confirmation](../../../../docs/architecture/money.md#confirmation) and
  [Withdrawal, removal and substitution](../../../../docs/architecture/money.md#withdrawal-removal-and-substitution)
  (the deposit extension's seam: the threshold, the advance and "retain up to the deposit").
- modules-and-extensions.md:
  [The Module interface](../../../../docs/architecture/modules-and-extensions.md#the-module-interface),
  [Extensions in view](../../../../docs/architecture/modules-and-extensions.md#extensions-in-view).

## Invariants

- This plugin stores only the per-event deposit amount, in the option `kalaka_deposits` (event ID to
  decimal text in the event's currency; no entry means no deposit). It owns no table.
- The threshold, the advance (the deposit payment request) and the retention rule are the money
  side's, built in a later slice. Nothing here contributes to confirmation or retention yet.
- The command line is the only way in: `wp kalaka deposit`. No screen, no route, no participant text.
- The amount is a non-negative decimal text with at most two decimals. It is not turned into money
  here; the money side converts it with the event's currency.
