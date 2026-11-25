# Aid

Adjudicated financial aid: one extension plugin, `kalaka-aid` (module `aid`). An event has aid when the organizers have given it a label; a registrant states in `aid_can_pay` what they can pay, which is the request `aid`; the one answer act writes the award into `aid_award`, and the fee component `aid` takes it off the participant's part as one `aid:award` line.

Table, created by the schema in `Module.php`: `kalaka_aid_settings` (event, label); only `AidSettingsRepository` writes it.

At the module root: `AidComponent` (the fee component, at the aid stage), `AidRequests` (the request source, with its `awaitingRule()` for the admin grid; its `writeAnswer()` is the award's only writer), `AidSettings` and `AidSettingsRepository`, `AidInput` (the checks before aid is proposed or saved) and the admin use case `SetAid` (`kalaka/set-aid`; full access only), which also declares the participant fields `aid_can_pay` and `aid_award` through the ParticipantFields Api.

`Api/` holds `Aid` (`label()`, and `propose()`, which gives a `ProposedAid` for `Pricing\Api\MoneySettings::preview()` and `apply()`).

Entry points: none. The money settings file carries it as `"aid": {"label": "<the organizers' name for the aid>"}` (`{"label": null}` turns it off; the component's settings must be a JSON object, so `"aid": null` is refused). The label must not be „Kedvezmény” (any case, trimmed), the manual adjustment's approved word; `propose()` and `kalaka/set-aid` refuse it with `InvalidInput`. The aid line is named by the label (`NamedFeeComponent`), so the money tables show the reduction under it.
