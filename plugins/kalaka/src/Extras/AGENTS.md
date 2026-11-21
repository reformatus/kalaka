# Extras

Extras is extra charges and the donation: a core module (`extras`), not a
plugin. It holds two fee components at the extras stage and is core money
([Money](../../../../docs/architecture/money.md#core-money-scope)).

## Sections it implements

- money.md: extra charges and the donation of
  [Core money scope](../../../../docs/architecture/money.md#core-money-scope),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply)
  (no tariff snapshot), the extras stage of
  [Materializer](../../../../docs/architecture/money.md#materializer), and the
  extras that [Congregation share](../../../../docs/architecture/money.md#congregation-share)
  never covers.
- events-and-forms.md: the number field of
  [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (Forms' type, whose settings the participant field keeps).
- modules-and-extensions.md:
  [Core modules](../../../../docs/architecture/modules-and-extensions.md#core-modules),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers).

## Invariants

- Its fee components `extras` and `donation` compose at the extras stage,
  return a value and write nothing. Each reads its settings as they are now:
  there is no tariff snapshot. The participant owes both; support and the
  congregation share compose earlier and never cover them.
- An extra charge has no condition. Each charge is its amount times the
  linked participant field's whole answer: a consent's `1` charges it once,
  a positive whole `n` charges it `n` times; anything else charges nothing.
  Every charge a registration owes is **one** `extras` line (the
  materializer keeps one line source per component and payer). A charge
  names an active consent or whole-number field, checked at save; the
  component reads only the registration's facts and its own settings, never
  `ParticipantFields::of()`.
- The donation is the value of the event's whole-number field, as its own
  `donation` line; empty, 0, a negative or a fractional value writes no line.
  Whether a field the donation or an extra charge names is money-relevant is
  N5's open question; the lock is S60's.
- The settings change only through `kalaka/set-extra-charges` and
  `kalaka/set-donation`, the save steps of a money settings apply
  (`Api\Extras::propose()`, `Api\Donation::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A charge's field and the
  donation's field are checked against the event's participant fields before
  anything is saved.
- Only this module writes `kalaka_extras_charges` and
  `kalaka_extras_donations`; `ChargeRepository` and `DonationRepository`
  are their only writers.
- No Hungarian text besides the number field's editor strings and refusals:
  a charge's label is the organizers' own setting.
