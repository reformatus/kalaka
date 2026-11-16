# Ranking

The module ranking (`kalaka-ranking`, module `ranking`): the ranking field type, and later the ranking proposer.

`Module.php` contributes `RankingFieldType` (`Contributions::fieldType()`) and registers the block `kalaka/ranking-field` from `build/Ranking/Blocks/ranking-field/` on `init`; the JavaScript build (`npm run build`, the second `wp-scripts build` in the root `package.json`) writes it there.

`RankingFieldType` checks an answer (a list of option keys: each one of the block's options, none twice, between `min` and `max`, else the field's own error), stores it as a JSON list in rank order, gives a choice value whose text numbers the labels (`1. Modul C, 2. Modul A`), and cleans a prefilled order (`prefilled()`: unknown keys and repeats dropped, the first `max` kept). `optionKeys()`, `labels()`, `min()` and `max()` read the block's attributes leniently: an option without a valid key, or a repeated key, is left out; `min` is at least 1 and `max` never below it.

`Blocks/ranking-field/`: `block.json` (attributes `key`, `label`, `required`, `options` as `{value, label}`, `min` 1, `max` 3), `render.php` (through `Forms\Api\FieldMarkup`: a `fieldset` whose `legend` is the title, the ranked options in an `ol`, the others in a `ul`, each a checkbox `<key>[]` with a Feljebb and a Lejjebb button, a `screen-reader-text` live region, then the error element), `view.js` (the script module: ticking ranks, unticking unranks; a ranked option is reordered by a drag of its handle (`RankingFieldType::HANDLE`, `aria-hidden`, shown once ranked, the only part of the row with `RankingFieldType::HANDLE_STYLE`: `touch-action: none`, no selection or callout, the grab cursor), hand-written on pointer events with the pointer captured by the list, ended by the window's `pointerup`/`pointercancel`, the list's `lostpointercapture` or a move with no button held, or by its buttons, whose accessible names are `<label> feljebb mozgatása` / `<label> lejjebb mozgatása` and whose edge ones are `aria-disabled`; a tick, an untick and a move keep the focus; a tick and a move fire `change` and the live region says `<label>, <n>. hely`, an untick empties it), `index.js` and `edit.js` (the editor: title, required, Legkevesebb választás, Legtöbb választás, and the options with a label and an Azonosító each; the form editor derives a missing option key from its label on save, as for a choice).

Tests: `tests/integration/` (the type, the form's walk, the markup, the participant field), `tests/js/` (the script, with core's `answers.js`).

Entry points: `init` (the block).
