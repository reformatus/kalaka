# Forms

Forms, the registration slot, field types, field definitions, conditions, prefill and form links.

A form is a `kalaka_form` post (registered in `Module.php` with its settings as post meta: `kalaka_event_id`, `kalaka_placement` and `kalaka_required_role`; the password is the post password). Its content is the definition, block markup built from the field blocks. Forms are built in the block editor, by full access only: the post type has `show_ui` and `show_in_rest` but no menu item, and `FormRestController` refuses every REST read without `kalaka_full_access`; the settings are in REST for the same users. A form's version is the ID of its latest revision that is not an autosave; WordPress saves none on insert, so `FormPosts::ensureRevision()` saves the first one, and revisions of forms are never pruned.

On every save (`wp_insert_post_data`), `FieldKeys` sets each field's missing key from its title and each choice option's missing value from its label, with `KeyDerivation`.

At the root: `FormPosts` (reads the posts, their revisions and their settings), `DefinitionParser` (reads the field blocks, in document order, out of any core blocks around them), `SlotRules` (the registration slot's markings), `AnswerValidator` (the server's check of answers against a definition), `KeyDerivation` and `FieldKeys` (keys and option values), `FormRestController`, `FieldMarkup` (the field blocks' shared markup) and `ThemeAdapters` (picks the theme adapter once per request).

`Api/` holds `Forms` (the facade: `form()`, `definition()`, `refusals()`, `validate()`), `Form`, `FormPlacement`, `FormDefinition`, `FormField`, `AccessRefusal`, `ValidatedAnswers`, `InvalidAnswers` and the field-type point: `FieldType`, `AbstractFieldType`, `FieldTypeInput`, `FieldAnswer` and the optional `MoneyRelevantFieldType`. Field types are contributed with `Contributions::fieldType()`. The theme-adapter point: `ThemeAdapter`, `ThemeAdapterInput`, `ThemeAdaptation`, `ElementRole` and `FieldWrapper`, contributed with `Contributions::themeAdapter()`.

`FieldType/` holds the seven core types: `NameFieldType`, `EmailFieldType`, `PhoneFieldType`, `DateFieldType`, `TextFieldType`, `ChoiceFieldType` and `ConsentFieldType`. `Blocks/<type>-field/` holds each type's `block.json` (title, the `kalaka` category, `ancestor` `kalaka/form`) and `render.php`; the blocks are registered from `src/` until the front end adds the JavaScript build, and their editor scripts come with it.

`Api\Forms` reads WordPress posts, so it runs before or after a use case's transaction, never inside it.

Entry points: none yet (the embed block and the form page come with the front end).
