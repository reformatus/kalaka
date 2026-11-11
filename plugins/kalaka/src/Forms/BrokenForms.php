<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The list of published forms whose current version does not parse, kept in a non-autoloaded option so
 * the site warning only reads it. It is recomputed when a form is saved, trashed or deleted or its
 * placement changes, and once when the option does not exist yet (the first request after an upgrade).
 */
final class BrokenForms
{
    public const OPTION = 'kalaka_broken_forms';

    /** @return list<string> one line per broken form: `#<ID> <title>: <problem> <subject>` */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /** Computes the list once, when it has never been stored (`admin_init`). */
    public static function ensure(): void
    {
        if (get_option(self::OPTION, null) === null) {
            self::refresh();
        }
    }

    /** `wp_after_insert_post`, `deleted_post`: recomputes the list after a form changed. */
    public static function onPostChange(int $postId, mixed $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : get_post_type($postId);
        if ($type === FormPosts::POST_TYPE || $type === 'revision') {
            self::refresh();
        }
    }

    /** `added_post_meta`, `updated_post_meta`, `deleted_post_meta`: a form's placement changed. */
    public static function onMetaChange(mixed $metaIds, int $postId, string $metaKey): void
    {
        if ($metaKey === FormPosts::META_PLACEMENT && get_post_type($postId) === FormPosts::POST_TYPE) {
            self::refresh();
        }
    }

    public static function refresh(): void
    {
        update_option(self::OPTION, self::compute(), false);
    }

    /** @return list<string> */
    public static function compute(): array
    {
        $forms = new Forms();
        $ids = get_posts([
            'post_type' => FormPosts::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        $broken = [];
        foreach ($ids as $id) {
            try {
                $form = $forms->form($id);
                if ($form !== null) {
                    $forms->definition($form);
                }
            } catch (SystemFailure $e) {
                [$code, $subject] = DefinitionCheck::split($e);
                $broken[] = '#' . $id . ' ' . get_the_title($id) . ': ' . trim($code . ' ' . $subject);
            }
        }

        return $broken;
    }
}
