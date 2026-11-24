# PriceLadder

The price ladder: one extension plugin, `kalaka-price-ladder` (module `price_ladder`). An event's tiers, each with
a key, a label, an amount in the event's currency and an optional limit of people, and the fee component
`price_ladder` that charges the chosen tier at the tier stage.

Table, created by the schema in `Module.php`: `kalaka_price_ladder_tiers`; only `TierRepository` writes it.

At the module root: `PriceLadderComponent` (the fee component, contributed in `Module::contribute()`),
`TierSelection` (the chosen tier in `price_tier`; none chosen or one no longer in the ladder refuses, with the
texts it holds), `LadderSlotRequirement` (an event with tiers requires `price_tier` in its registration form),
`TierClaims` (the seat claim of the chosen limited tier), `TierSeatPool` (a limited tier's seat pool,
`tier.<key>`), `TierLimitGuard` (no limit is given to a tier an open registration chose), `TierRecord` and
`TierRepository`, `LadderInput` (reads a ladder as sent, for the preview and the save) and the admin use case
`SetPriceLadder` (`kalaka/set-price-ladder`; full access only), which also declares the participant field
`price_tier` (a choice of the tiers) through the ParticipantFields Api.

`Api/` holds `PriceLadder` (`tiers()`, `seatsLeft()`: the form variable `seats_left.price_tier.<tier key>`, and
`propose()`, which gives a `ProposedLadder` for `Pricing\Api\MoneySettings::preview()` and `apply()`) and `Tier`.

Entry points: none yet (the settings screen is S72's).
