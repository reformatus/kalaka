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
- A form-to-backend link is by key
  ([Fields](../../../../docs/architecture/events-and-forms.md#fields)): an
  extra charge names no field. The participant field whose key equals the
  charge's key calls it, and each charge is its amount times that field's
  whole answer: a consent's `1` charges it once, a positive whole `n` charges
  it `n` times; anything else charges nothing. A charge key no field uses
  charges nobody, with no warning. The save refuses only a number field with
  the charge's key that allows decimals (the multiplier is whole) and a charge
  key that is the donation's. The component reads only the registration's
  facts and its own settings, never `ParticipantFields::of()`.
- An extra charge has no condition. Each charge is **its own** ledger line,
  `extras.<key>` (the materializer keeps one line source per component line
  and payer, `FeeTargets::ofLines()`); a charge key holds at most 57
  characters so the source key fits 64. Removing a charge, or a registration
  that no longer owes it, reverses that charge's line alone. The line's name
  is the charge's label, read from its source by whoever shows it.
- The donation is the value of the event's number field with the fixed key
  `adomany`, set by Kaláka: one per event, as its own `donation` line, charged
  exactly as stored (no whole-number rule; the form field's settings decide
  decimals). Empty, 0, a negative value or an event without that field writes
  no line. It has no setting and no use case: the key alone decides.
- Whether a field a charge or the donation meets is money-relevant is N5's
  open question; the lock is S60's.
- The extra charges change only through `kalaka/set-extra-charges`, the save
  step of a money settings apply (`Api\Extras::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. A number field with a
  charge's key is checked against the event's participant fields before
  anything is saved.
- Only this module writes `kalaka_extras_charges`; `ChargeRepository` is its
  only writer.
- No Hungarian text besides the number field's editor strings and refusals:
  a charge's label is the organizers' own setting.
