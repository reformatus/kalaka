# Extras

Extras is lunch and the donation: one feature plugin, `kalaka-extras`
(module `extras`), an extension that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
It holds two fee components at the extras stage and the amount field type.

## Sections it implements

- money.md: lunch and the donation of [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply)
  (no tariff snapshot), the extras stage of [Materializer](../../../../docs/architecture/money.md#materializer),
  and the extras that [Congregation share](../../../../docs/architecture/money.md#congregation-share)
  never covers.
- events-and-forms.md: a money-relevant field type of
  [Fields](../../../../docs/architecture/events-and-forms.md#fields),
  [Conditions](../../../../docs/architecture/events-and-forms.md#conditions)
  (a lunch item's condition is one condition over the registration's facts).
- modules-and-extensions.md:
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee component and the field type),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- Its fee components `lunch` and `donation` compose at the extras stage,
  return a value and write nothing. Each reads its settings as they are now:
  there is no tariff snapshot. The participant owes both; support and the
  congregation share compose earlier and never cover them.
- Lunch owes the sum of the event's items whose condition holds over the
  registration's facts, through `Forms\Api\Availability`, as one `lunch`
  line; the module never evaluates a condition itself.
- The donation owes the value of the event's amount field, as one
  `donation` line; empty or 0 owes nothing and writes no line.
- The amount field type (`kalaka/amount-field`) is money-relevant
  (`MoneyRelevantFieldType`): a whole number of forints, at least 0, with
  the block's optional smallest, step and largest amount applying only to an
  amount actually given. Its markup is core's text field markup
  (`Forms\Api\FieldMarkup::textLike()`), nothing of its own.
- The settings change only through `kalaka/set-lunch` and
  `kalaka/set-donation`, the save steps of a money settings apply
  (`Api\Lunch::propose()`, `Api\Donation::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A lunch condition is
  checked against the keys a registration's facts declare, and the donation
  field must be an active amount field of the event, before anything is
  saved.
- Only this module writes `kalaka_extras_lunch_items` and
  `kalaka_extras_donations`; `LunchItemRepository` and `DonationRepository`
  are their only writers.
- No Hungarian text besides the plugin name, the block's title, its editor
  settings and its refusal messages: lunch item labels are the organizers'
  own settings.
