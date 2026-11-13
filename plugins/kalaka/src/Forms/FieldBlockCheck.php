<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;

/**
 * The site-wide check of the blocks in published forms (modules-and-extensions.md, "Failure isolation"):
 * a `kalaka/` block that is not a registered block type closes the site. A zip or checkout without
 * `build/` leaves every field block unregistered, so any published form catches it.
 *
 * The `kalaka/` block names of each published form are cached in an option, like `BrokenForms`: it is
 * cleared when a form is saved (its status changing included) or deleted, and computed again on the next
 * request. The names are checked against the block registry on every request, so a missing `build/`
 * closes the site whatever the cache holds.
 */
final class FieldBlockCheck
{
    public const MODULE_ID = 'forms';

    public const OPTION = 'kalaka_form_blocks';

    /** Called on `wp_loaded`, after `init` has registered the blocks. */
    public function run(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        foreach (self::stored() as $formId => $names) {
            foreach ($names as $name) {
                if (!$registry->is_registered($name)) {
                    LoadState::close(new LoadFailure(
                        self::MODULE_ID,
                        sprintf('Form %d holds the block %s, which is not a registered block type.', $formId, $name)
                    ));
                    break;
                }
            }
        }
    }

    /**
     * The `kalaka/` block names of each published form, by form ID, from the cache, computed and stored
     * when it is missing.
     *
     * @return array<int, list<string>>
     */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            $stored = self::compute();
            update_option(self::OPTION, $stored, true);
        }
        $names = [];
        foreach ($stored as $formId => $list) {
            if (is_int($formId) && is_array($list)) {
                $names[$formId] = array_values(array_filter($list, 'is_string'));
            }
        }

        return $names;
    }

    /**
     * `wp_after_insert_post`, `deleted_post`: a form changed, so the cache is cleared. Only a form counts:
     * its revisions and autosaves are not its content, and a restore saves the form itself.
     */
    public static function onPostChange(int $postId, mixed $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : get_post_type($postId);
        if ($type === FormPosts::POST_TYPE) {
            delete_option(self::OPTION);
        }
    }

    /**
     * Every published form's `kalaka/` block names, each once, in document order; forms by ascending ID.
     *
     * @return array<int, list<string>>
     */
    public static function compute(): array
    {
        $forms = get_posts([
            'post_type' => FormPosts::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters' => true,
        ]);
        $names = [];
        foreach ($forms as $form) {
            $blocks = array_values(parse_blocks($form->post_content));
            $names[$form->ID] = array_values(array_unique(self::names($blocks)));
        }

        return $names;
    }

    /**
     * The `kalaka/` block names, in document order.
     *
     * @param list<array<string, mixed>> $blocks parsed blocks
     * @return list<string>
     */
    private static function names(array $blocks): array
    {
        $names = [];
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && str_starts_with($name, 'kalaka/')) {
                $names[] = $name;
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : [];
            array_push($names, ...self::names($inner));
        }

        return $names;
    }
}
