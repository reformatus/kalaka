<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Forms\FieldType\ConsentFieldType;
use Kalaka\Forms\FieldType\DateFieldType;
use Kalaka\Forms\FieldType\EmailFieldType;
use Kalaka\Forms\FieldType\NameFieldType;
use Kalaka\Forms\FieldType\PhoneFieldType;
use Kalaka\Forms\FieldType\TextFieldType;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class Module extends AbstractModule
{
    /** The core field blocks, in `Blocks/`, each with its title in its `block.json`. */
    private const BLOCKS = ['name', 'email', 'phone', 'date', 'text', 'choice', 'consent'];

    /** The block category of Kaláka's blocks in the block editor's inserter. */
    public const BLOCK_CATEGORY = 'kalaka';

    public function id(): string
    {
        return 'forms';
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
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
    }

    /** @internal the `init` callback */
    public function register(): void
    {
        $this->registerPostType();
        $this->registerMeta();
        foreach (self::BLOCKS as $name) {
            register_block_type(__DIR__ . '/Blocks/' . $name . '-field');
        }

        // events-and-forms.md, "Forms implementation": a form's version is its latest revision, and
        // revisions of forms are never pruned.
        add_filter('wp_' . FormPosts::POST_TYPE . '_revisions_to_keep', static fn (): int => -1);
        add_action('wp_after_insert_post', [new FormPosts(), 'ensureRevision'], 10, 2);

        // events-and-forms.md, "Fields": keys and option values come from the visible text, set on save.
        add_filter('wp_insert_post_data', [FieldKeys::class, 'onInsert']);
        // A published form never goes from working to broken through an edit.
        add_filter('wp_insert_post_data', [DefinitionCheck::class, 'onInsert'], 20, 2);
        add_filter('rest_pre_insert_' . FormPosts::POST_TYPE, [DefinitionCheck::class, 'onRestInsert'], 10, 2);
        add_filter('block_categories_all', [$this, 'blockCategories']);
        add_filter('allowed_block_types_all', [BlockGate::class, 'filter'], 10, 2);
        add_filter('enter_title_here', [$this, 'titlePlaceholder'], 10, 2);
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
