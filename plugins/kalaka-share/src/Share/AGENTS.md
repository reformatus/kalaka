# Share

The share is the congregation share: one feature plugin, `kalaka-share` (module
`share`), an extension that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
The registrant's congregation pays part or all of the base price, as a
zero-sum pair of lines. Sponsor and church support is the other extension
(`kalaka-support`); the share composes after it.

## Sections it implements

- money.md: [Congregation share](../../../../docs/architecture/money.md#congregation-share),
  the share stage of [Materializer](../../../../docs/architecture/money.md#materializer),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply).
- events-and-forms.md: [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (the declared `extension` fields `share_mode` and `share_amount`).
- modules-and-extensions.md:
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee-component point and the payer kind),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- The share is on for an event when it has a row in `kalaka_share_settings`, written only through `kalaka/set-share`,
  which the money settings apply runs (`Api\Share::propose()`, the component `share`'s settings document
  `{"on": bool}`; any other shape is refused); only `ShareSettingsRepository` writes the table. On, the save declares
  the two participant fields in the same transaction through core's `declareExtensionField()` as declarer `share`
  (the module's ID, `SetShare::DECLARER`), money relevant: `share_mode`, a choice of the fixed values `none`,
  `shared` and `full`, and `share_amount`, a number with no declared minimum, maximum or decimals; both
  extension-sourced and form-writable. The form links to them by key, and any subset of the modes may be offered,
  never an added one. Their keys are judged by core's `ParticipantFields::checkDeclarable()` in the proposal and
  the save: a form's field of the type is adopted (the setup order does not matter); a staff or system field,
  another type or another declarer's field refuses with `RuleViolation`. Off, the row is deleted; the fields and
  their values stay, and the apply takes the pairs away. [UNAPPROVED N141, N142, N152, N153, N154]
- The component reads the registration's facts (its two answers), its own setting and the stage totals; never a
  charge line. A missing answer, a form without the field or `none` is no share; `share_amount` is read only for
  `shared`, and an empty, zero, negative or non-numeric amount is no share (no refusal). `share_mode` is not a slot
  requirement. X' = max(0, min(share, base - support)), with the base the tier stage's participant total and the
  support the support stage's; an X' of zero returns `FeeTargets::none()`, so an earlier pair is reversed and no
  zero line is written. The pair is one undivided `share` line per payer, participant -X' and congregation +X'.
  [UNAPPROVED N144, N145, N150]
- Nothing re-checks `share_mode` against the tier at evaluation: the form's option conditions are the judge at
  submit. Both answers are money relevant, and the lock after the first write is core's (CF4, #245, D5; not enforced
  on this base), not this module's: once it lands an additional form cannot overwrite them. Until then, and where
  one of the two was never written (an additional form that holds one of `price_tier` and `share_mode` but not the
  other), a mismatch is reachable. Author both together. [UNAPPROVED N146]
- There is no dedicated path for a congregation that declines after the registration. Staff correct each payer's
  balance by hand with the existing per-payer entries (correction, Díj, Kedvezmény) under the payer rule below;
  Kaláka does not handle the case specially: no unlock and re-answer, no "set share" act. [UNAPPROVED N157]
- The module declares the payer kind `congregation` (label „Gyülekezet”) through a `PayerKindSource`
  (`SharePayerKind`), so a congregation payment can be recorded by hand and is credited to the congregation alone.
  The kind applies per event, only where the share is on (`Api\Share::isOn()`). One rule for the money tab and the
  server (`PayerKinds::payersOf()` and `mayRecordFor()`): a payment, refund, correction, Díj or Kedvezmény is
  accepted for the congregation where the share is on or where it already has lines on the registration, and a
  payer that already has lines stays shown. [UNAPPROVED N147, T65]
- No UI. Its Hungarian texts are the plugin name, the titles and options of the two fields (`SetShare`), the
  payer kind's label and the line name.
