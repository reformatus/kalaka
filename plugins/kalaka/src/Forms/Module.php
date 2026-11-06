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
use Kalaka\Forms\Value\BindingSource;

final class Module extends AbstractModule
{
    /** The core field blocks, in `Blocks/`. */
    private const BLOCKS = ['name', 'email', 'phone', 'date', 'text', 'choice', 'consent'];

    /** The condition branch blocks, in `Blocks/`. */
    private const BRANCH_BLOCKS = ['if', 'else-if', 'else'];

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
        foreach (self::BRANCH_BLOCKS as $name) {
            register_block_type(__DIR__ . '/Blocks/' . $name);
        }
        register_block_bindings_source(BindingSource::NAME, [
            'label' => 'Kaláka-érték',
            'get_value_callback' => [BindingSource::class, 'value'],
        ]);

        // events-and-forms.md, "Forms implementation": a form's version is its latest revision, and
        // revisions of forms are never pruned.
        add_filter('wp_' . FormPosts::POST_TYPE . '_revisions_to_keep', static fn (): int => -1);
        add_action('wp_after_insert_post', [new FormPosts(), 'ensureRevision'], 10, 2);
    }

    /** Forms are created and edited by code and WP-CLI only: no screen, no REST route, no editor. */
    private function registerPostType(): void
    {
        $full = AdminAccess::FULL_ACCESS;
        register_post_type(FormPosts::POST_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor', 'revisions'],
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
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_EVENT_ID, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => false,
        ]);
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_PLACEMENT, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
        ]);
        register_post_meta(FormPosts::POST_TYPE, FormPosts::META_REQUIRED_ROLE, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
        ]);
    }
}
