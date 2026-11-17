# PriceLadder

The price ladder: one extension plugin, `kalaka-price-ladder` (module `price_ladder`). An event's tiers, each with
a key, a label, an amount in the event's currency, an availability condition and an optional deadline, and the
fee component `price_ladder` that charges the applying tier at the tier stage.

Table, created by the schema in `Module.php`: `kalaka_price_ladder_tiers`; only `TierRepository` writes it.

At the module root: `PriceLadderComponent` (the fee component, contributed in `Module::contribute()`),
`TierSelection` (which tier applies: the first open at `registered_at` whose condition holds), `TierRecord` and
`TierRepository`, `LadderInput` (reads a ladder as sent, for the preview and the save) and the admin use case
`SetPriceLadder` (`kalaka/set-price-ladder`; full access only).

`Api/` holds `PriceLadder` (`tiers()`, and `propose()`, which gives a `ProposedLadder` for
`Pricing\Api\MoneySettings::preview()` and `apply()`) and `Tier`.

Entry points: none yet (the settings screen is S72's).
