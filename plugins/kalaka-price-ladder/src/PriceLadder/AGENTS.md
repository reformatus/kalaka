# PriceLadder

PriceLadder is the price ladder: an event's price tiers, one feature plugin,
`kalaka-price-ladder` (module `price_ladder`), an extension that uses only
core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
So far it holds the tier stage; tier quotas come later. The ladder sets only
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

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Its fee component `price_ladder` composes at the tier stage, returns a
  value and writes nothing. It reads the ladder as it is now: there is no
  tariff snapshot.
- A tier is a key, a label, an amount and an order: no condition and no
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
  `ParticipantFields\Api\ParticipantFields::declareExtensionField()`; a
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
  before anything is saved.
- Only this module writes `kalaka_price_ladder_tiers`; `TierRepository` is
  its only writer.
- No UI. Its Hungarian texts are the plugin name, the title of `price_tier`
  (`PriceLadderComponent::FIELD_TITLE`) and the two staff refusals
  (`TierSelection`); tier labels are the organizers' own settings.
