<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FormPage;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Cli\FormSeeder;
use Kalaka\Forms\Condition\DateReference;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Forms\FieldType\DateFieldType;
use Kalaka\Forms\FieldType\EmailFieldType;
use Kalaka\Forms\FieldType\NameFieldType;
use Kalaka\Forms\FieldType\PhoneFieldType;
use Kalaka\Forms\FieldType\TextFieldType;
use Kalaka\Forms\Rest\FormRoutes;
use Kalaka\Forms\Value\BindingSource;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /** The embed block, in `Blocks/`. */
    private const EMBED_BLOCK = 'form';

    /** The core field blocks, in `Blocks/`, each with its title in its `block.json`. */
    private const BLOCKS = ['name', 'email', 'phone', 'date', 'text', 'choice', 'consent'];

    /** The handle of the form's view stylesheet, named by the embed block's `viewStyle`. */
    public const VIEW_STYLE = 'kalaka-form-view';

    /** The block category of Kaláka's blocks in the block editor's inserter. */
    public const BLOCK_CATEGORY = 'kalaka';

    /**
     * The event's participant fields, keyed by (event ID, field key). `field_key` and `type`
     * are compared exactly (`utf8mb4_nopad_bin`); `options` is JSON; times are UTC `datetime(6)`.
     */
    private const PARTICIPANT_FIELDS = <<<'SQL'
CREATE TABLE {prefix}kalaka_participant_fields (
  event_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  type varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  title text CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  options longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  source varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  retired_at datetime(6) DEFAULT NULL,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  position int(10) unsigned NOT NULL,
  form_writable tinyint(1) NOT NULL,
  PRIMARY KEY  (event_id,field_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;
    /** The condition branch blocks, in `Blocks/`. */
    private const BRANCH_BLOCKS = ['if', 'else-if', 'else'];

    /** The page and after-submit blocks, in `Blocks/`. */
    private const STRUCTURE_BLOCKS = ['form-page', 'after-submit'];

    public function id(): string
    {
        return 'forms';
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::PARTICIPANT_FIELDS]);
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    /** The core field types contribute through the field-type point like any module. */
    public function contribute(Contributions $to): void
    {
        $to->fieldType(new NameFieldType());
        $to->fieldType(new EmailFieldType());
        $to->fieldType(new PhoneFieldType());
        $to->fieldType(new DateFieldType());
        $to->fieldType(new TextFieldType());
        $to->fieldType(new ChoiceFieldType());
        $to->fieldType(new ConsentFieldType());
        $to->siteWarning(new BrokenFormsWarning());
        $to->seeder(new FormSeeder());
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
        add_action('rest_api_init', [ParticipantFieldsRoute::class, 'register']);

        // On `wp_loaded`, after `init` has registered the blocks; at once when it has already fired (activation).
        $check = new FieldBlockCheck();
        if (did_action('wp_loaded') > 0) {
            $check->run();
        } else {
            add_action('wp_loaded', [$check, 'run']);
        }
        add_action('template_redirect', [$this, 'preventFormPageCaching']);
        add_action('rest_api_init', [new FormRoutes(), 'register']);
        add_filter('rest_request_after_callbacks', [FormRoutes::class, 'noStore'], 10, 3);
        // events-and-forms.md, "Forms implementation": the shortcode for page builders that cannot hold blocks.
        add_shortcode(FormPage::SHORTCODE, [$this, 'shortcode']);
    }

    /**
     * The `[kalaka_form id="…"]` shortcode: the embed block's renderer, for the form it names.
     *
     * @internal the shortcode callback
     * @param array<string, string>|string $attributes
     */
    public function shortcode(array|string $attributes): string
    {
        FormPage::preventCaching();
        $id = is_array($attributes) ? absint($attributes['id'] ?? 0) : 0;

        return render_block([
            'blockName' => FormPage::BLOCK,
            'attrs' => ['formId' => $id],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /**
     * @internal the `template_redirect` callback: a singular page that places a form is never cached,
     * so no visitor is served another's submit token (N6).
     */
    public function preventFormPageCaching(): void
    {
        Boundary::background('form page caching', static function (): void {
            $post = is_singular() ? get_queried_object() : null;
            if ($post instanceof \WP_Post && FormPage::placesForm($post->post_content)) {
                FormPage::preventCaching();
            }
        });
    }

    /** @internal the `init` callback */
    public function register(): void
    {
        $this->registerPostType();
        $this->registerMeta();
        // The blocks are registered from `build/`, where the JavaScript build writes them (engineering.md, "Builds").
        $blocks = dirname(KALAKA_FILE) . '/build/Forms/Blocks/';
        // The form's loading state (dim and spinner): plain CSS, which the build does not copy, so it is
        // served from `src/` (the zip carries `src/`).
        wp_register_style(
            self::VIEW_STYLE,
            plugins_url('src/Forms/Blocks/form/view.css', KALAKA_FILE),
            [],
            KALAKA_VERSION
        );
        $this->registerEmbedBlock($blocks . self::EMBED_BLOCK);
        foreach (self::BLOCKS as $name) {
            register_block_type($blocks . $name . '-field');
        }
        foreach ([...self::BRANCH_BLOCKS, ...self::STRUCTURE_BLOCKS] as $name) {
            register_block_type($blocks . $name);
        }
        register_block_bindings_source(BindingSource::NAME, [
            'label' => 'Kaláka-érték',
            'get_value_callback' => [BindingSource::class, 'value'],
        ]);

        // events-and-forms.md, "Forms implementation": a form's version is its latest revision, and
        // revisions of forms are never pruned.
        add_filter('wp_' . FormPosts::POST_TYPE . '_revisions_to_keep', static fn (): int => -1);
        add_action('wp_after_insert_post', [new FormPosts(), 'ensureRevision'], 10, 2);

        // events-and-forms.md, "Fields": keys and option values come from the visible text, set on save.
        // Derived across the form's event (owner ruling 2026-11-11), whose ID may be only in `$postarr`.
        add_filter('wp_insert_post_data', [FieldKeys::class, 'onInsert'], 10, 2);
        // A published form never goes from working to broken through an edit.
        add_filter('wp_insert_post_data', [DefinitionCheck::class, 'onInsert'], 20, 2);
        add_filter('rest_pre_insert_' . FormPosts::POST_TYPE, [DefinitionCheck::class, 'onRestInsert'], 10, 2);
        // A form with fields keeps its event (owner ruling 2026-11-13, B2(a)), whatever writes the setting.
        add_filter('add_post_metadata', [FormEvent::class, 'onWrite'], 10, 4);
        add_filter('update_post_metadata', [FormEvent::class, 'onWrite'], 10, 4);
        add_filter('delete_post_metadata', [FormEvent::class, 'onDelete'], 10, 3);
        // A form that submissions refer to is never deleted permanently.
        add_filter('pre_delete_post', [FormDeletion::class, 'onPreDelete'], 10, 3);
        // The event's participant fields follow its forms, once the saved form and its settings are stored.
        add_action('wp_after_insert_post', [ParticipantFieldSync::class, 'onPostChange'], 15, 2);
        add_action('before_delete_post', [ParticipantFieldSync::class, 'beforeDelete'], 10, 2);
        add_action('deleted_post', [ParticipantFieldSync::class, 'afterDelete'], 5);
        // The broken-form list the site warning reads, kept current on every change of a form.
        add_action('wp_after_insert_post', [BrokenForms::class, 'onPostChange'], 20, 2);
        add_action('deleted_post', [BrokenForms::class, 'onPostChange'], 10, 2);
        // The cached block names of the site-wide block check, cleared on every change of a form.
        add_action('wp_after_insert_post', [FieldBlockCheck::class, 'onPostChange'], 20, 2);
        add_action('deleted_post', [FieldBlockCheck::class, 'onPostChange'], 10, 2);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [BrokenForms::class, 'onMetaChange'], 10, 3);
        }
        add_action('admin_init', [BrokenForms::class, 'ensure']);
        add_action('admin_notices', [DefinitionCheck::class, 'notice']);
        add_filter('block_categories_all', [$this, 'blockCategories']);
        add_filter('allowed_block_types_all', [BlockGate::class, 'filter'], 10, 2);
        add_filter('enter_title_here', [$this, 'titlePlaceholder'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'editorScriptData']);
    }

    /**
     * The embed block, from `build/`. Without `build/` (D10) the site is closed by `FieldBlockCheck`, and
     * the block is registered from its source `block.json`'s attributes with its server render only, no
     * script, so a page or shortcode that places a form still shows the closed site's failure text.
     *
     * @internal called by `register()`
     */
    public function registerEmbedBlock(string $built): void
    {
        if (is_file($built . '/block.json')) {
            register_block_type($built);
            return;
        }
        $source = __DIR__ . '/Blocks/' . self::EMBED_BLOCK;
        $metadata = wp_json_file_decode($source . '/block.json', ['associative' => true]);
        $attributes = is_array($metadata) && is_array($metadata['attributes'] ?? null)
            ? $metadata['attributes']
            : [];
        register_block_type(FormPage::BLOCK, [
            'attributes' => $attributes,
            'render_callback' => static fn (array $given): string => self::renderWithoutBuild($source, $given),
        ]);
    }

    /**
     * The embed block's server render, as WordPress runs a `render` file: `$attributes` in scope.
     *
     * @param array<string, mixed> $attributes
     */
    private static function renderWithoutBuild(string $source, array $attributes): string
    {
        ob_start();
        try {
            require $source . '/render.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The `enqueue_block_editor_assets` action: what the server tells the form editor, printed before the
     * editor script as `window.kalakaFormEditor` (read by `Blocks/form/server.js`).
     *
     * @internal
     */
    public function editorScriptData(): void
    {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered(FormPage::BLOCK);
        $handle = $type === null ? null : ($type->editor_script_handles[0] ?? null);
        if (!is_string($handle)) {
            return;
        }
        $data = wp_json_encode(self::editorData());
        wp_add_inline_script($handle, 'window.kalakaFormEditor = ' . $data . ';', 'before');
    }

    /**
     * What the form editor needs from the server, so it copies none of it: the registered field-type
     * block names (extension field types included) with the value type of each answer, the keys a form's
     * and the after-submit section's conditions may read, and a date reference's bounds.
     *
     * @return array{
     *     fieldTypes: array<string, string>,
     *     eventTypes: array<string, string>,
     *     afterSubmitTypes: array<string, string>,
     *     dateReference: array{maxYears: int, maxDays: int}
     * }
     */
    public static function editorData(): array
    {
        $value = static fn (ValueType $type): string => $type->value;

        return [
            'fieldTypes' => array_map(
                static fn (FieldType $type): string => $type->valueType()->value,
                Contributions::current()->fieldTypes()
            ),
            'eventTypes' => array_map($value, EventValues::TYPES),
            'afterSubmitTypes' => array_map($value, AfterSubmit::TYPES),
            'dateReference' => ['maxYears' => DateReference::MAX_YEARS, 'maxDays' => DateReference::MAX_DAYS],
        ];
    }

    /**
     * The `enter_title_here` filter: a form's title is its internal name.
     *
     * @internal
     */
    public function titlePlaceholder(string $placeholder, \WP_Post $post): string
    {
        return $post->post_type === FormPosts::POST_TYPE ? 'Űrlap nevének hozzáadása' : $placeholder;
    }

    /**
     * The `block_categories_all` filter: Kaláka's category, once.
     *
     * @internal
     * @param array<array{slug: string, title: string, icon?: string|null}> $categories
     * @return array<array{slug: string, title: string, icon?: string|null}>
     */
    public function blockCategories(array $categories): array
    {
        foreach ($categories as $category) {
            if ($category['slug'] === self::BLOCK_CATEGORY) {
                return $categories;
            }
        }
        $categories[] = ['slug' => self::BLOCK_CATEGORY, 'title' => 'Kaláka', 'icon' => null];

        return $categories;
    }

    /**
     * Forms are built in the block editor (events-and-forms.md, "Forms implementation"), by full access
     * only: every capability, REST reads included (`FormRestController`), needs `kalaka_full_access`. The
     * editor opens a form from its own link; the type adds no admin menu item. Its labels are the ones the
     * block editor and the list screen show for a type without a menu or a public view.
     */
    private function registerPostType(): void
    {
        $full = AdminAccess::FULL_ACCESS;
        register_post_type(FormPosts::POST_TYPE, [
            'public' => false,
            'labels' => [
                'name' => 'Űrlapok',
                'singular_name' => 'Űrlap',
                'add_new' => 'Új űrlap',
                'add_new_item' => 'Új űrlap hozzáadása',
                'new_item' => 'Új űrlap',
                'edit_item' => 'Űrlap szerkesztése',
                'view_items' => 'Űrlapok megtekintése',
                'all_items' => 'Összes űrlap',
                'search_items' => 'Űrlapok keresése',
                'not_found' => 'Nem található űrlap.',
                'not_found_in_trash' => 'Nem található űrlap a lomtárban.',
                'insert_into_item' => 'Beszúrás az űrlapba',
                'uploaded_to_this_item' => 'Feltöltve ehhez az űrlaphoz',
                'filter_items_list' => 'Űrlapok listájának szűrése',
                'items_list_navigation' => 'Űrlapok listájának navigációja',
                'items_list' => 'Űrlapok listája',
                'item_published' => 'Űrlap közzétéve.',
                'item_published_privately' => 'Űrlap privátként közzétéve.',
                'item_reverted_to_draft' => 'Űrlap visszaállítva vázlattá.',
                'item_trashed' => 'Űrlap lomtárba helyezve.',
                'item_scheduled' => 'Űrlap időzítve.',
                'item_updated' => 'Űrlap frissítve.',
            ],
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'rest_controller_class' => FormRestController::class,
            // `custom-fields` lets the settings below show in REST, where only full access can write them.
            'supports' => ['title', 'editor', 'revisions', 'custom-fields'],
            // With `map_meta_cap`, WordPress would remember kalaka_full_access as the name of a post meta
            // capability and stop treating it as a plain capability of users.
            'map_meta_cap' => false,
            'capabilities' => [
                'edit_post' => $full,
                'read_post' => $full,
                'delete_post' => $full,
                'edit_posts' => $full,
                'edit_others_posts' => $full,
                'delete_posts' => $full,
                'publish_posts' => $full,
                'read_private_posts' => $full,
                'create_posts' => $full,
            ],
        ]);
    }

    /**
     * The form settings, as post meta (events-and-forms.md, "Forms implementation"); called by `register()`.
     *
     * @internal
     */
    public function registerMeta(): void
    {
        $fullAccess = static fn (bool $allowed, string $metaKey, int $postId, int $userId): bool
            => user_can($userId, AdminAccess::FULL_ACCESS);
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_EVENT_ID, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $fullAccess,
        ]);
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_PLACEMENT, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => [
                'schema' => ['type' => 'string', 'enum' => ['', ...array_column(FormPlacement::cases(), 'value')]],
            ],
            'auth_callback' => $fullAccess,
        ]);
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_REQUIRED_ROLE, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $fullAccess,
        ]);
    }
}
