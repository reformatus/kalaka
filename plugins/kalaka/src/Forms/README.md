# Forms

Forms, the registration slot, field types, field definitions, values and conditions, prefill and form links.

A form is a `kalaka_form` post (registered in `Module.php` with its settings as post meta: `kalaka_event_id`, `kalaka_placement` and `kalaka_required_role`; the password is the post password). Its content is the definition, block markup built from the field blocks. Forms are created and edited by code and WP-CLI only: no admin screen and no REST route. A form's version is the ID of its latest revision; WordPress saves none on insert, so `FormPosts::ensureRevision()` saves the first one, and revisions of forms are never pruned.

At the root: `FormPosts` (reads the posts, their revisions and their settings), `DefinitionParser` (reads the field blocks, in document order, out of any core blocks and condition branches around them), `SlotRules` (the registration slot's markings, none of them inside a condition branch) and `AnswerValidator` (the server's check of answers against a definition, dropping the answers of branches that do not hold).

`Api/` holds `Forms` (the facade: `form()`, `definition()`, `refusals()`, `validate()`), `Form`, `FormPlacement`, `FormDefinition`, `FormField`, `FormBranch`, `AccessRefusal`, `ValidatedAnswers`, `InvalidAnswers`, the value API (`Values`, the facade with `render()`, `Value`, `ValueType` and `ValueContext`) and the field-type point: `FieldType`, `AbstractFieldType`, `FieldTypeInput`, `FieldAnswer` and the optional `MoneyRelevantFieldType`. Field types are contributed with `Contributions::fieldType()`.

`FieldType/` holds the seven core types: `NameFieldType`, `EmailFieldType`, `PhoneFieldType`, `DateFieldType`, `TextFieldType`, `ChoiceFieldType` and `ConsentFieldType`. `Blocks/<type>-field/` holds each type's `block.json` and `render.php`; the blocks are registered from `src/` until the front end adds the JavaScript build.

`Condition/` holds the stored condition format: `ConditionParser` reads it into a `Condition` (made of `Comparison`s, `Operator`s and `MatchMode`s) for the keys a `ConditionScope` allows (`FormScope` in a form, `ContextScope` over a `ValueContext` anywhere else), `ConditionEvaluator` decides whether it holds (pure), and `Chains` finds the `kalaka/if`, `kalaka/else-if` and `kalaka/else` chains among sibling blocks.

`Value/` holds the renderer behind `Values::render()`: `ChainPass` keeps the held branch of every chain, `BindingSource` is the Block Bindings source `kalaka/value`, `InlineValues` fills `<span data-kalaka-value="key">` with the HTML API, and `RenderStack` holds the context of the render in progress. `Blocks/if/`, `Blocks/else-if/` and `Blocks/else/` hold the three branch blocks; they render their inner blocks, with no wrapper, only inside `Values::render()` and nothing anywhere else.

`Api\Forms` reads WordPress posts, so it runs before or after a use case's transaction, never inside it.

Entry points: none yet (the embed block and the form page come with the front end).
