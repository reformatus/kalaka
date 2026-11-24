# PriceLadder

PriceLadder is the price ladder: an event's price tiers, one feature plugin,
`kalaka-price-ladder` (module `price_ladder`), an extension that uses only
core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
It holds the tier stage and the tiers' quotas. The ladder sets only
the base price: the share between payers is decided by the registrant's
answers (the share extension, S29), never by a tier.

## Sections it implements

- money.md: the price ladder of [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply)
  (no tariff snapshot),
  the tier stage of [Materializer](../../../../docs/architecture/money.md#materializer).
- events-and-forms.md: [Forms and the registration slot](../../../../docs/architecture/events-and-forms.md#forms-and-the-registration-slot)
  (the required `price_tier`), [Fields](../../../../docs/architecture/events-and-forms.md#fields) (the
  declared `extension` field `price_tier`).
- modules-and-extensions.md:
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee component and the slot requirement), [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).
- money.md: [Quota places](../../../../docs/architecture/money.md#quota-places) (the tier's limit).
- foundation.md: [Facts, decisions and computed values](
  ../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (the seat rule: a seat is a stored fact, the count is computed).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Its fee component `price_ladder` composes at the tier stage, returns a
  value and writes nothing. It reads the ladder as it is now: there is no
  tariff snapshot.
- A tier is a key, a label, an amount, an order and an optional limit of
  people: no condition and no
  deadline (the owner's ruling 2026-11-19). It is chosen only through the
  declared field `price_tier`; date and congregation guards are the form
  author's own conditions on the field's options.
- The tier chosen in `price_tier` applies, at its current amount, and is
  never swapped for another; a later change of any other answer never
  re-checks it, and a waitlist admission keeps the choice. A chosen tier no
  longer in the ladder, or no `price_tier` answer, refuses
  (`Pricing\Api\FeeRefusal`, with the staff texts in `TierSelection`, none
  for the registrant): a base of zero never passes silently.
- An event with tiers requires `price_tier` in its registration form
  (`LadderSlotRequirement`, a `Forms\Api\SlotRequirement`); Forms closes a
  form without it. No fallback: a registration without an answer refuses.
- The ladder declares `price_tier` (source `extension`, form-writable, a
  choice of the tiers: key as value, label as label) in every save, through
  `ParticipantFields\Api\ParticipantFields::declareExtensionField()`, as declarer
  `price_ladder` (the module's ID) and money relevant (its value is locked after its first write) [UNAPPROVED N301]; a
  removed tier's option is retired. An empty ladder on an event that never
  had one declares nothing.
- The applied tier reaches the later stages as the computed value
  `price_tier.applied`, never stored.
- An event with tiers has its base from the ladder (`ProvidesBase`), so core's
  fixed price is never charged for it; a tier that owes nothing owes nothing
  for the base.
- A tier carries no share mode, and the ladder's charge is the participant's
  whole tier amount: the base price only.
- The ladder changes only through `kalaka/set-price-ladder`, the save step of
  a money settings apply (`Api\PriceLadder::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A key given twice is refused
  before anything is saved. The component is a `Pricing\Api\ConfigurableFeeComponent`:
  the money settings file of `wp kalaka money-settings` reads and writes the tiers
  through `settingsToArray()` and `settingsFromArray()`, which only call
  `Api\PriceLadder`.
- A tier with a limit is a seat pool `tier.<key>` under the one seat rule
  (`TierSeatPool`, quota lock level: `TierRepository::lockLevel()` is `Quota`).
  The limit is the tier's `seat_limit`, a setting, never a seat. `TierClaims`
  (a `SeatClaimSource`) claims the seat of the tier chosen in `price_tier`, only
  while the registration is entitled to it now; a full tier refuses the submit
  through core. The ladder writes no seat and never moves one: a price change
  moves nothing, and the amount a tier owes never reads a seat.
  A registration admitted or restored into a full tier keeps its tier and takes
  its seat over the limit (`Seats::takeOver()`, the count shows over the limit),
  with the existing "full" warning to staff [UNAPPROVED N3].
  `Api\PriceLadder::seatsLeft()` serves the form variable
  `seats_left.price_tier.<tier key>`.
- A limit is not given to a tier an open (`registered` or `waitlisted`)
  registration has chosen (`TierLimitGuard`, in the preview and in
  `SetPriceLadder`; a lower limit is allowed) [UNAPPROVED N2]. Removing a
  chosen tier is refused by the re-price, not by this guard.
- Only this module writes `kalaka_price_ladder_tiers`; `TierRepository` is
  its only writer.
- No UI. The refusal of a full tier is core's text (`SeatsFull`). Its Hungarian texts are the plugin name,
  the title of `price_tier` (`PriceLadderComponent::FIELD_TITLE`) and the two staff refusals
  (`TierSelection`); tier labels are the organizers' own settings.
