# Share

The congregation share: one extension plugin, `kalaka-share` (module `share`). An event has the share when its row exists; a registrant's two answers, `share_mode` (none, shared or full) and `share_amount`, decide how much of the base price the congregation pays, and the fee component `share` writes that as one zero-sum pair of `share` lines, participant -X' and congregation +X'.

Table, created by the schema in `Module.php`: `kalaka_share_settings` (event); only `ShareSettingsRepository` writes it.

At the module root: `ShareComponent` (the fee component, at the share stage, its pair named alike), `ShareSettings` and `ShareSettingsRepository`, `ShareInput` (the check of the two keys by core's `checkDeclarable()`: a form's field of the type is adopted, any other holder refuses), `SharePayerKind` (the payer kind `congregation`, a `PayerKindSource` that applies where the share is on) and the admin use case `SetShare` (`kalaka/set-share`; full access only), which also declares the participant fields `share_mode` and `share_amount` through the ParticipantFields Api.

`Api/` holds `Share` (`isOn()`, and `propose()`, which gives a `ProposedShare` for `Pricing\Api\MoneySettings::preview()` and `apply()`).

Entry points: none. The money settings file carries it as `"share": {"on": true}`.
