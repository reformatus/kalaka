# PriceLadder

The price ladder: one extension plugin, `kalaka-price-ladder` (module `price_ladder`). An event's tiers, each with
a key, a label, an amount in the event's currency, an availability condition and an optional deadline, and the
fee component `price_ladder` that charges the applying tier at the tier stage.

Table, created by the schema in `Module.php`: `kalaka_price_ladder_tiers`; only `TierRepository` writes it.

At the module root: `PriceLadderComponent` (the fee component, contributed in `Module::contribute()`),
`TierSelection` (which tier applies: the chosen one in `price_tier` when it is open at `registered_at` and its
condition holds, else the first such tier when none is chosen; otherwise it refuses, with the texts it holds),
`TierRecord` and `TierRepository`, `LadderInput` (reads a ladder as sent, for the preview and the save) and the
admin use case `SetPriceLadder` (`kalaka/set-price-ladder`; full access only), which also declares the participant
field `price_tier` (a choice of the tiers) through the ParticipantFields Api.

`Api/` holds `PriceLadder` (`tiers()`, and `propose()`, which gives a `ProposedLadder` for
`Pricing\Api\MoneySettings::preview()` and `apply()`) and `Tier`.

Entry points: none yet (the settings screen is S72's).
