# Divi4

Divi4 is the Divi 4 theme adapter: one extension plugin, `kalaka-divi4`
(module `divi4`), that uses only core's public API
([Packaging](../../../../docs/architecture/modules-and-extensions.md#packaging)).

## Sections it implements

- modules-and-extensions.md: [Extensions in view](../../../../docs/architecture/modules-and-extensions.md#extensions-in-view)
  (the Divi 4 theme adapter), the theme-adapter point of
  [The extension surface](../../../../docs/architecture/modules-and-extensions.md#the-extension-surface),
  [Contracts](../../../../docs/architecture/modules-and-extensions.md#contracts).
- engineering.md: [WordPress surfaces](../../../../docs/architecture/engineering.md#wordpress-surfaces)
  (theme adapters).

## Invariants

- The plugin uses core only through `Kalaka\<Module>\Api\…` (deptrac); core
  never uses the plugin.
- The adapter returns data only (classes per element role, the `p` wrapper,
  Divi's form red, the card return page's `ContentFrame` case and body classes, all from core's closed lists); it
  never returns HTML and never changes or filters core's markup.
- It applies only when the active theme's template is `Divi` at a version
  below 5 (the theme's `Version` header); an unknown version does not apply.
- Labels stay visible and radio buttons and checkboxes stay the browser's
  own: no Divi class that hides them (`et_pb_contact_form_label`, Divi's drawn
  `input` + `label > i` controls).
- Divi's own form styles load through Divi's mechanism
  (`et_required_module_assets`) on pages whose content places a form or the
  status block, and are named above the fold too
  (`et_dynamic_assets_modules_atf`, after Divi's own list), so Divi prints
  them inline as critical CSS: its deferred stylesheets need JavaScript. The
  plugin's own CSS maps core's neutral base onto Divi's form look: the
  `--kalaka-…` properties set on Divi's contact form (Divi's input greys, no
  border or radius; the filled +/× in the same greys), plus what they cannot
  reach (the spacing of `div` fields; the ranking's outlined ↑/↓: no fill,
  a 2-pixel border in Divi's input grey, its text grey; a focus ring Divi's
  `:focus { outline: 0 }` removes; the ranking's row gap, handle, lines,
  lists, the empty rows' dashed places Divi's reset hides, and the dragged
  row's lift, white with a shadow; the number field's affix position, the
  input's padding from the room core's script measures, and a muted affix
  grey lighter than Divi's digits). It never changes markup.
  Functional too: on an iPhone (Divi's `body.iphone`) it gives Kaláka's own
  checkboxes and radio buttons their native appearance back, which Divi's
  contact-form rule sets to `initial` (0×0).
- The registrant's money table (`kalaka/status`) is mapped onto Divi's table look
  by the same stylesheet, on pages whose content places that block or its
  shortcode: Divi gives a `td` a `#eee` top line and a `th` none, so the first
  column gets the same line (not on the rows core's own separator, `kalaka-rule`,
  marks). Core keeps the table's structure and its emphasis (`em`, `strong`).
- Divi's button icon glyph keeps its look, with an empty text alternative
  (`content: "\35" / ""`), for Kaláka's own buttons only (the form's, the
  card payment button): a screen reader does not read it.
- The card return page (core's `Portal\Rest\CardReturnPage`) sits in Divi's page frame: the adapter returns
  core's closed `ContentFrame::Page` (core renders the markup), the role `page_body` class `et_full_width_page` and
  the sidebar layout classes to drop; under any other theme core's neutral frame stays. The adapter returns no HTML
  and registers no filter for it.
- The plugin stores nothing.
