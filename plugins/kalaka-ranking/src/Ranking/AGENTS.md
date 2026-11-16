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
  (no look of its own).

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
- A prefilled order drops unknown keys and repeats silently, then keeps the
  first `max` (`RankingFieldType::prefilled()`); too few stay, and the submit
  asks for more.
- The options are the block's own list of `{value, label}` until S44's
  assignment sets exist; every option is offered until then (TODO(S76)).
- The markup is core's field markup (`Forms\Api\FieldMarkup`): the browser's
  own checkboxes named `<key>[]` in a choice group, ranked ones first in an
  ordered list, so the form sends them in rank order. A ranked option moves
  by a drag of its handle (pointer events: mouse, pen, touch; only the handle
  drags and only it takes `touch-action: none`, so the rest of the row scrolls
  and taps as usual; owner 2026-11-16; it is a box of its own beside the label,
  at least 44 by 44 pixels, that listens for its own clicks, so a touch on it
  is not moved to the label, and a tap on it does nothing) or by its Feljebb and Lejjebb buttons
  (the keyboard's and the screen reader's way; the handle is `aria-hidden`); every move fires `change` and is announced in
  the field's live region, as is a tick, and an untick empties it. A press ends
  wherever its pointer is released or cancelled, and nothing reorders without a
  held pointer. A tick, an untick or a move keeps the focus where it was; the
  edge buttons are `aria-disabled`, never `disabled`. Each button's accessible
  name names its option. The script holds no rule and changes only the
  order and the place of the options; the server checks everything at
  submit.
- The plugin stores nothing of its own.
