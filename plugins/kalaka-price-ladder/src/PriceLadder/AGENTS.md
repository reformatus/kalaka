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
  (no tariff snapshot; deadlines count from `registered_at`),
  the tier stage of [Materializer](../../../../docs/architecture/money.md#materializer).
- events-and-forms.md: [Conditions](../../../../docs/architecture/events-and-forms.md#conditions)
  (a tier's availability is one condition over the registration's facts),
  [Fields](../../../../docs/architecture/events-and-forms.md#fields) (the
  declared `extension` field `price_tier`).
- modules-and-extensions.md:
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee component), [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Its fee component `price_ladder` composes at the tier stage, returns a
  value and writes nothing. It reads the ladder as it is now: there is no
  tariff snapshot.
- A tier is available when its deadline lets in the registration's
  `registered_at` and its condition holds over its facts, through
  `Forms\Api\Availability`; the module never evaluates a condition itself.
  No time-dependent rule reads "now" or the submit time.
- The tier chosen in `price_tier` applies when it is available and is never
  swapped for another; without a chosen tier, the first available one in
  ladder order applies. A chosen tier that is not available, or no available
  tier, refuses (`Pricing\Api\FeeRefusal`, with the texts in
  `TierSelection`): a base of zero never passes silently.
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
- A deadline is stored as the instant its last day ends in the site time
  zone, fixed when it is saved; the component compares instants only.
- The ladder changes only through `kalaka/set-price-ladder`, the save step of
  a money settings apply (`Api\PriceLadder::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A condition is checked
  against the keys a registration's facts declare before it is saved. The
  component is a `Pricing\Api\ConfigurableFeeComponent`: the money settings
  file of `wp kalaka money-settings` reads and writes the tiers through
  `settingsToArray()` and `settingsFromArray()`, which only call `Api\PriceLadder`.
- Only this module writes `kalaka_price_ladder_tiers`; `TierRepository` is
  its only writer.
- No UI. Its Hungarian texts are the plugin name, the title of `price_tier`
  (`PriceLadderComponent::FIELD_TITLE`) and the three refusals
  (`TierSelection`); tier labels are the organizers' own settings.
