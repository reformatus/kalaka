# Ranking

Ranking is the module ranking: one extension plugin, `kalaka-ranking`
(module `ranking`), that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).
It holds the ranking field type now and the ranking proposer later (S77).

## Sections it implements

- modules-and-extensions.md: [Extensions in view](../../../../docs/architecture/modules-and-extensions.md#extensions-in-view)
  (module ranking), the request mode of
  [Assignment sets](../../../../docs/architecture/modules-and-extensions.md#assignment-sets),
  the field-type point of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface),
  [Contracts](../../../../docs/architecture/modules-and-extensions.md#contracts).
- events-and-forms.md: [Fields](../../../../docs/architecture/events-and-forms.md#fields)
  (an extension's field type),
  [Forms implementation](../../../../docs/architecture/events-and-forms.md#forms-implementation).
- engineering.md: [WordPress surfaces](../../../../docs/architecture/engineering.md#wordpress-surfaces)
  (the structure, and a neutral look on core's base in the `kalaka` layer).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- The ranking is a field type contributed through `Contributions::fieldType()`:
  it returns values and writes nothing. Its answer is a request, a field value
  that core stores; it is not an assignment.
- Its block is `kalaka/ranking-field`, so a published form that holds it while
  the plugin is inactive closes the site like any unregistered `kalaka/` block.
- An answer is the ordered list of option keys, rank 1 first: each one of the
  block's options, none twice, at least `min` (1 or more) and at most `max`
  (never below `min`). It is stored as that list in JSON; for a condition it
  is a choice value, and its text numbers the option labels.
- A stored ranking opens in its order (`RankingFieldType::prefill()`, the
  field-type contract, rendered from `FieldMarkup::prefilled()`): unknown
  keys and repeats are dropped silently, the rest kept in order, then the
  first `max`; too few stay, and the submit asks for more.
- The options are the block's own list of `{value, label}` until S44's
  assignment sets exist; every option is offered until then (TODO(S76)).
- The markup is core's field markup (`Forms\Api\FieldMarkup`): a choice group
  whose options are rows (handle, the option's text, ↑, ↓, and the browser's
  own checkbox `<key>[]`, drawn as „+” / „×”), ranked ones first in an ordered
  list, so the form sends them in rank order. The option's text is no
  `label`: a tap on it does nothing. The two lists are named „Választott
  sorrend” / „Többi lehetőség”.
- A ranked option moves by a drag (a mouse from anywhere on the row; a finger
  or pen by the handle at once, or anywhere on the row after a long press of
  300 ms; only the handle has `touch-action: none`, so the row scrolls as
  usual) or by its ↑/↓ buttons, the keyboard's and the screen reader's way
  (the handle is `aria-hidden`). A press ends wherever its pointer is
  released or cancelled; nothing reorders without a held pointer. A tick, an
  untick or a move keeps the focus where it was. Every move and tick fires
  `change`; the live region says „{modul}, {n}. helyen” after a tick or a
  move, „{modul}, kivéve a sorrendből” after an untick, and adds „Megtelt a
  sorrend: …” only to the tick that reaches the maximum.
- Each control's accessible name names its option; the toggle's says what it
  does now. Controls that do nothing now are `aria-disabled`, never
  `disabled` (focus stays): the edge ↑/↓, and every „+” at the maximum,
  which stays a Tab stop; pressing one says „Megtelt a sorrend: …” again.
  They show no hand or hover tint, and while focused only their icon is
  faint, so the focus ring shows in full.
- Under the ranked options, as many empty rows as the minimum still lacks
  (on an optional field only after the first addition: "none, or at least
  the minimum"), the first with a line, in a third named list („Üres helyek
  a sorrendben”); a counter that updates, „{k} / {max} kiválasztva”. Every
  toggle is described by the counter and the line. While only one option is
  ranked, its ↑/↓ are not shown and its handle keeps its place, empty (CSS,
  so the server's render is right too). On a phone (below 600 pixels) the
  controls sit under the option's text in both lists. The script mirrors `RankingFieldType`'s texts and rules for
  them; it holds no rule beyond them, and the server checks everything at
  submit.
- The CSS (`style.css`) is the structure, unlayered (layout, 44-pixel
  targets, what dragging and the toggle need), and a neutral look in the
  `kalaka` cascade layer with `:where()` selectors on core's `--kalaka-…`
  variables. The +/× toggle is filled and the ↑/↓ are outlined (owner,
  2026-11-18): the ↑/↓ are core's outlined base buttons, without
  `wp-element-button`; the toggle cannot carry it either, since a theme's
  checkbox rules and its button rules would mix on it (Twenty Twenty-One:
  white on white), so its fill is a box of its own (`::after`, under the
  icon) in core's `--kalaka-button-…` properties, and a theme's own checkbox
  drawing (background, border, mark) is removed. A theme that fills every
  `button` (Twenty Twenty-One) fills the ↑/↓ too: the theme wins.
- The plugin stores nothing of its own.
