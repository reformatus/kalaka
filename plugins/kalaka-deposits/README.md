# Kaláka – Foglalók

The deposits extension plugin (`kalaka-deposits`, module `deposits`): it stores the deposit amount of
each event and shows it on the command line. It uses only core's public API
([Packaging](../../docs/architecture/modules-and-extensions.md#packaging)).

The amount is one option, `kalaka_deposits`: a map of event ID to a decimal text in the event's
currency. An event without an entry has no deposit. `DepositSettings` reads and writes it.

```
wp kalaka deposit <event>            shows the deposit, or says there is none
wp kalaka deposit <event> <amount>   sets it (greater than zero, at most two decimals)
wp kalaka deposit <event> --off      removes it
```

The plugin also contributes the retention rule `deposit` (`src/Deposits/Retention/`): an event that has it
keeps, of a leaving registration's payments, up to its deposit (the participant payer counted first, then the
other payers in order), and keeps nothing when it has no deposit amount.

There is no screen, route or table. The code is in `src/Deposits/` (see its `AGENTS.md`); tests are in
`tests/integration/`.
