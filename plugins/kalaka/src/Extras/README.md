# Extras

Extra charges and the donation: a core module (`extras`). Two fee components at the extras stage, `extras` (each
charge a registration owes is its own line, `extras:<key>`) and `donation` (the value of the event's number field
with the fixed key `adomany`, on its own line, with no setting).

A charge is linked by key: the participant field whose key equals the charge's key calls it (a consent charges
once, a whole number `n` charges `n` times); a key no field uses charges nobody.

The table, created by the schema in `Module.php`: `kalaka_extras_charges` (only `Charges/ChargeRepository` writes
it). There is no tariff snapshot.

`Charges/` holds `ChargeRecord` and `ChargeRepository`, `ChargesInput` (reads the charges as sent, for the preview
and the save; a key holds at most 40 characters), `ChargeField` (a number field with a charge's key must take whole
numbers) and the admin use case `SetExtraCharges` (`kalaka/set-extra-charges`; full access only). `ExtrasComponent`
and `Donation/DonationComponent` are the fee components.

`Api/` holds `Extras` (`charges()`, and `propose()`, which gives a `ProposedExtras` for
`Pricing\Api\MoneySettings::preview()` and `apply()`) and `Charge`.

Entry points: none of its own; the settings screen is S72's.
