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
  (`et_required_module_assets`) on pages whose content places a form; the
  plugin's own CSS is structural only (the spacing of `div` fields).
- The plugin stores nothing.
