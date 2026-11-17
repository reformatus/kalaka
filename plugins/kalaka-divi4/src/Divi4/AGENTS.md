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
  Divi's form red); it never changes or filters core's markup.
- It applies only when the active theme's template is `Divi` at a version
  below 5 (the theme's `Version` header); an unknown version does not apply.
- Labels stay visible and radio buttons and checkboxes stay the browser's
  own: no Divi class that hides them (`et_pb_contact_form_label`, Divi's drawn
  `input` + `label > i` controls).
- Divi's own form styles load through Divi's mechanism
  (`et_required_module_assets`) on pages whose content places a form. The
  plugin's own CSS maps core's neutral base onto Divi's form look: the
  `--kalaka-…` properties set on Divi's contact form (Divi's input greys, no
  border or radius), plus what they cannot reach (the spacing of `div`
  fields; the ranking's ↑/↓, whose `wp-element-button` takes WordPress's
  global button colours, in the same greys; a focus ring Divi's
  `:focus { outline: 0 }` removes; the ranking's row gap, handle, lines,
  lists, the empty rows' dashed places Divi's reset hides, and the dragged
  row's lift, white with a shadow). It never changes markup.
  Functional too: on an iPhone (Divi's `body.iphone`) it gives Kaláka's own
  checkboxes and radio buttons their native appearance back, which Divi's
  contact-form rule sets to `initial` (0×0).
- The plugin stores nothing.
