# Ranking

The module ranking (`kalaka-ranking`, module `ranking`): the ranking field type, and later the ranking proposer.

`Module.php` contributes `RankingFieldType` (`Contributions::fieldType()`) and registers the block `kalaka/ranking-field` from `build/Ranking/Blocks/ranking-field/` on `init`; the JavaScript build (`npm run build`, the second `wp-scripts build` in the root `package.json`) writes it there.

`RankingFieldType` checks an answer (a list of option keys: each one of the block's options, none twice, between `min` and `max`, else the field's own error), stores it as a JSON list in rank order, gives a choice value whose text numbers the labels (`1. Modul C, 2. Modul A`), and opens a stored ranking (`prefill()`, the field-type contract: unknown keys and repeats dropped, the rest kept in order, the first `max` of them). `optionKeys()`, `labels()`, `min()` and `max()` read the block's attributes leniently: an option without a valid key, or a repeated key, is left out; `min` is at least 1 and `max` never below it.

`Blocks/ranking-field/`: `block.json` (attributes `key`, `label`, `required`, `options` as `{value, label}`, `min` 1, `max` 3; `style` the built `style-index.css`), `render.php` (through `Forms\Api\FieldMarkup`: a `fieldset` whose `legend` is the title; the order it opens with from `FieldMarkup::prefilled()`; the field carries `data-kalaka-min`, `data-kalaka-max` and, when required, `data-kalaka-required`; the ranked options in an `ol` named `RANKED_NAME`, the minimum's empty rows in their own `ol` named `EMPTY_NAME` (`emptyRows()`, the first with `minimumLine()`), the counter (`COUNTER`), both describing every toggle (`aria-describedby`), the others in a `ul` named `OTHERS_NAME`; each option a row of the handle `HANDLE`, its text (`data-kalaka-label`, no `label`), the ↑/↓ icon buttons (`UP_ICON`, `DOWN_ICON`, named `UP_NAME`/`DOWN_NAME`) and its checkbox `<key>[]` (`data-kalaka-toggle`, named `ADD_NAME`/`REMOVE_NAME`), the arrows core's outlined base buttons and the toggle filled (`--kalaka-button-…`); core's `kalaka-visually-hidden` live region; the error element), `style.css` (the structure unlayered; the neutral look in `@layer kalaka`), `view.js` (the script module: + ranks, × unranks; a mouse drags from anywhere on a ranked row, a finger by the handle at once or after `LONG_PRESS` elsewhere on the row; the arrows move; the counter, the empty rows and the „+” at the maximum follow every change (`refresh()`); the live region says the place, the removal, once that the order is full, and again on a press of a „+” at the maximum), `index.js` and `edit.js` (the editor, from core's field-editor pieces in `window.kalakaFieldEditor`, which `block.json` loads first by naming `kalaka-form-editor-script` before `index.js`: the title, Kötelező, Legkevesebb választás and Legtöbb választás in the settings, then core's participant-field and key panels; the one keeper gives a copy a fresh key).

Tests: `tests/integration/` (the type, the form's walk, the markup, the participant field), `tests/js/` (the script, with core's `answers.js`).

Entry points: `init` (the block).

S44: `RankingFieldType` is a `Forms\Api\RequestFieldType` and an `OptionsFieldType`: the `set` attribute decides, block by
block, where the options come from (`requestSet()`). A block with a `set` holds no options of its own: core fills its
`options` attribute from that set, offering only the options whose eligibility condition holds; `set` 0 is a set not
chosen yet, refused on save. A block with no `set` holds its own `options`, as before sets existed. The editor's source
choice (Lehetőségek › Lehetőségek forrása: Saját lehetőségek or Beosztás, after the settings) shows the options editor
or the set picker, which reads `GET kalaka-admin/v1/events/{id}/assignment-sets`, and the canvas lists the options. A ranking the form
re-renders is ranked again in the order it held (`restart()` in `view.js`, on core's `kalaka:field-swapped`).

`Cli/RankingSeeder.php`: IVK's tracks and module set on the first published sample event (`wp kalaka seed`).
