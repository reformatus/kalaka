# Extras

Extra charges and the donation: a core module (`extras`). Two fee components at the extras stage, `extras` (every
charge a registration owes, on one line) and `donation` (the value of the event's whole-number field, on its own
line).

Tables, created by the schema in `Module.php`: `kalaka_extras_charges` (only `Charges/ChargeRepository` writes it)
and `kalaka_extras_donations` (only `Donation/DonationRepository` writes it). There is no tariff snapshot.

`Charges/` holds `ChargeRecord` and `ChargeRepository`, `ChargesInput` (reads the charges as sent, for the preview
and the save), `ChargeField` (the field a charge names must be an active consent or whole-number field) and the
admin use case `SetExtraCharges` (`kalaka/set-extra-charges`; full access only). `Donation/` holds
`DonationRecord` and `DonationRepository`, `DonationField` (the field must be an active whole-number field) and
the admin use case `SetDonation` (`kalaka/set-donation`; full access only). `ExtrasComponent` and
`Donation/DonationComponent` are the fee components.

`Api/` holds `Extras` (`charges()`, and `propose()`, which gives a `ProposedExtras` for
`Pricing\Api\MoneySettings::preview()` and `apply()`), `Charge`, `Donation` (`field()`, `propose()`) and
`ProposedDonation`.

Entry points: none of its own; the settings screen is S72's.
