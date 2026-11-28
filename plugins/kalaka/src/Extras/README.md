# Extras

Extra charges and the donation: a core module (`extras`). Two fee components at the extras stage, `extras` (each
charge a registration owes is its own line, `extras:<key>`) and `donation` (the value of the event's number field
with the fixed key `adomany`, on its own line, with no setting).

A charge declares its participant field when the charges are saved (key = the charge's key, a consent or a number by the
charge's `type`, consent when none is given, the charge's label as the title; `extension` source, form-writable). A
form field joins it by the key and never creates it; a form field of the same type that already holds the key is
adopted, a staff or system field or another type refuses the save, and so does a form of the event that holds a
number charge's key as a number field with other decimals (`FieldDecimalsHeld`). The field calls
the charge (a consent charges once, a whole number `n` charges `n` times). The same save declares the donation's
number field `adomany`, with or without charges.

The table, created by the schema in `Module.php`: `kalaka_extras_charges` (only `Charges/ChargeRepository` writes
it). There is no tariff snapshot.

`Charges/` holds `ChargeRecord` and `ChargeRepository`, `ChargesInput` (reads the charges as sent, for the preview
and the save; a key holds at most 40 characters), `ChargeField` (the check of the field a key already has: no other
staff or system field, no other type, and a number multiplier takes whole numbers) and the admin use case `SetExtraCharges` (`kalaka/set-extra-charges`; full access only). `ExtrasComponent`
and `Donation/DonationComponent` are the fee components.

`Api/` holds `Extras` (`charges()`, and `propose()`, which gives a `ProposedExtras` for
`Pricing\Api\MoneySettings::preview()` and `apply()`) and `Charge`.

Entry points: none of its own; the settings screen is S72's.
