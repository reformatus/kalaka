# Divi4

The Divi 4 theme adapter: one extension plugin, `kalaka-divi4` (module `divi4`). It contributes `DiviThemeAdapter` through `Contributions::themeAdapter()`: under Divi 4 (the template `Divi`, also with a child theme, at a version below 5; Divi 5 gets its own adapter later) a form gets Divi's contact-form classes (`et_pb_contact et_pb_contact_form` on the form, `et_pb_contact_field` on each field, `input et_pb_contact_message` on text controls, `et_pb_contact_submit et_pb_button` on the button), `p` wrappers for text-like fields and Divi's form red (`#ff0000`) as the error colour.

`Module::boot()` asks Divi's dynamic assets for the contact form's styles (`et_required_module_assets`) and adds the spacing of `div` fields (a choice, a consent) on pages whose content holds the `kalaka/form` block or the `[kalaka_form]` shortcode.
