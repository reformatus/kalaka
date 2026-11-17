# PriceLadder

PriceLadder is the price ladder: an event's price tiers, one feature plugin,
`kalaka-price-ladder` (module `price_ladder`), an extension that uses only
core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
So far it holds the tier stage; a tier's share is S29's and tier quotas come
later.

## Sections it implements

- money.md: the price ladder of [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply)
  (no tariff snapshot; deadlines count from `registered_at`),
  the tier stage of [Materializer](../../../../docs/architecture/money.md#materializer).
- events-and-forms.md: [Conditions](../../../../docs/architecture/events-and-forms.md#conditions)
  (a tier's availability is one condition over the registration's facts).
- modules-and-extensions.md: [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee component), [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Its fee component `price_ladder` composes at the tier stage, returns a
  value and writes nothing. It reads the ladder as it is now: there is no
  tariff snapshot.
- The applying tier is the first, in ladder order, whose deadline lets in
  the registration's `registered_at` and whose condition holds over its
  facts, through `Forms\Api\Availability`; the module never evaluates a
  condition itself. No time-dependent rule reads "now" or the submit time.
- With no applying tier the ladder owes nothing, and core's fixed price, when
  the event has one, is the base instead.
- A deadline is stored as the instant its last day ends in the site time
  zone, fixed when it is saved; the component compares instants only.
- The ladder changes only through `kalaka/set-price-ladder`, the save step of
  a money settings apply (`Api\PriceLadder::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A condition is checked
  against the keys a registration's facts declare before it is saved.
- Only this module writes `kalaka_price_ladder_tiers`; `TierRepository` is
  its only writer.
- No UI and no Hungarian text besides the plugin name: tier labels are the
  organizers' own settings.
