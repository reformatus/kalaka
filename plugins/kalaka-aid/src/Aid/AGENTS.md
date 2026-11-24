# Aid

Aid is adjudicated financial aid (the organizations call it a scholarship):
one feature plugin, `kalaka-aid` (module `aid`), an extension that uses only
core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
The participant states what they can pay, staff or a partner decide whether
and how much to grant through the one "answer a request" act, and no payment
is asked until decided. Sponsor and church support is the other extension
(`kalaka-support`).

## Sections it implements

- money.md: the aid of [Core money scope](../../../../docs/architecture/money.md#core-money-scope)
  (the request, the award as a participant field of source "extension", only
  the participant's own part),
  the aid stage of [Materializer](../../../../docs/architecture/money.md#materializer),
  [Money settings apply](../../../../docs/architecture/money.md#money-settings-apply).
- foundation.md: [Facts, decisions and computed values](../../../../docs/architecture/foundation.md#facts-decisions-and-computed-values)
  (an answer is a fact; "awaits an answer" is computed, never stored).
- events-and-forms.md: [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (the declared `extension` fields `aid_can_pay` and `aid_award`).
- modules-and-extensions.md:
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface)
  (the fee-component and request-source points),
  [Identifiers](../../../../docs/architecture/modules-and-extensions.md#identifiers),
  [Schema ownership](../../../../docs/architecture/modules-and-extensions.md#schema-ownership).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- An event has aid when it has a row in `kalaka_aid_settings` (the label the
  organizers give it); only this module writes the table, `AidSettingsRepository`
  being its only writer. Aid changes only through `kalaka/set-aid`, the save
  step of a money settings apply (`Api\Aid::propose()` with
  `Pricing\Api\MoneySettings`): preview, then apply. Turning aid off deletes
  the row only; the stored requests and awards stay, and the component
  writes no line for an event without aid.
- The save declares two participant fields of source `extension` (number
  fields): `aid_can_pay`, form-writable, where the participant states what
  they can pay, and `aid_award`, never form-writable. A form links to
  `aid_can_pay` by key; who sees it is a condition on the form. Their keys
  are refused while another source holds them.
- An aid request, `aid`, exists while the registration holds a value in
  `aid_can_pay` and the event has aid. Staff answer 0 when nothing is
  needed. The request source reads only the field values and the settings.
- The award is written only by `AidRequests::writeAnswer()`, called by the
  answer act: the amount for a yes (above zero), 0 for a no. Nothing else
  sets `aid_award`; nobody sets a derived fact directly.
- The fee component `aid` composes at the aid stage, returns a value and
  writes nothing. The award is one participant-side item line `aid:award`,
  capped at what the participant owes after the tier, support and share
  stages and before the extras; the cap never refuses an answer. No award, or
  an award of 0, writes no line; an unanswered request has no award, so it
  reduces nothing. It never reads a charge line.
- Aid reduces only the participant's part, never the congregation's share.
- No UI. Its Hungarian texts are the plugin name and the titles of the two
  fields (`SetAid`); the organizers' label of the aid is their own setting.
