# Extras

Lunch and the donation: one extension plugin, `kalaka-extras` (module `extras`). Two fee components at the extras
stage, `lunch` (the sum of an event's lunch items whose condition holds) and `donation` (the value of the event's
amount field), and the money-relevant amount field type `kalaka/amount-field`.

Tables, created by the schema in `Module.php`: `kalaka_extras_lunch_items` (only `Lunch/LunchItemRepository` writes
it) and `kalaka_extras_donations` (only `Donation/DonationRepository` writes it).

`Lunch/` holds `LunchComponent` (the fee component), `LunchItemRecord` and `LunchItemRepository`, `LunchInput`
(reads items as sent, for the preview and the save) and the admin use case `SetLunch` (`kalaka/set-lunch`; full
access only). `Donation/` holds `AmountFieldType` (the field type), `DonationComponent` (the fee component),
`DonationRecord` and `DonationRepository`, `DonationField` (the field a setting names must be an active amount field
of the event) and the admin use case `SetDonation` (`kalaka/set-donation`; full access only). `Blocks/amount-field/`
holds the block's `block.json`, `render.php` (core's text field markup, through `Forms\Api\FieldMarkup`), `index.js`
and `edit.js`, built into `build/` by `npm run build`.

`Api/` holds `Lunch` (`items()`, and `propose()`, which gives a `ProposedLunch` for
`Pricing\Api\MoneySettings::preview()` and `apply()`), `LunchItem`, `Donation` (`field()`, `propose()`) and
`ProposedDonation`.

Entry points: `init` (`Module::register()`, the amount field's block from `build/`); the settings screen is S72's.
