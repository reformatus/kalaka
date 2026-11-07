<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Reads `kalaka_form` posts: their settings (post meta), their password and their revisions.
 * Forms are posts and their settings post meta (foundation.md, "Cross-cutting rules").
 */
final class FormPosts
{
    public const POST_TYPE = 'kalaka_form';
    public const META_EVENT_ID = 'kalaka_event_id';
    public const META_PLACEMENT = 'kalaka_placement';
    public const META_REQUIRED_ROLE = 'kalaka_required_role';

    /**
     * A published form of the right kind with valid settings, or null.
     *
     * @throws SystemFailure when the form has no revision (problem `no_revision`)
     */
    public function form(int $id): ?Form
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
            return null;
        }

        $eventId = get_post_meta($post->ID, self::META_EVENT_ID, true);
        if (!is_scalar($eventId) || preg_match('/\A[1-9][0-9]*\z/', (string) $eventId) !== 1) {
            return null;
        }
        $placement = FormPlacement::tryFrom((string) get_post_meta($post->ID, self::META_PLACEMENT, true));
        if ($placement === null) {
            return null;
        }
        $role = get_post_meta($post->ID, self::META_REQUIRED_ROLE, true);

        return new Form(
            $post->ID,
            (int) $eventId,
            $placement,
            is_string($role) && $role !== '' ? $role : null,
            $post->post_password !== '',
            $this->latestRevision($post->ID)
        );
    }

    /**
     * The definition's block markup in one version of the form.
     *
     * @throws SystemFailure when the version is not a revision of the form (problem `unknown_version`)
     */
    public function content(Form $form, int $version): string
    {
        $revision = $version > 0 ? get_post($version) : null;
        if (
            !$revision instanceof \WP_Post
            || $revision->post_type !== 'revision'
            || $revision->post_parent !== $form->id
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_version form ' . $form->id);
        }

        return $revision->post_content;
    }

    /**
     * Saves the first revision of a form that has none: WordPress saves none on insert
     * (`wp_save_post_revision_on_insert()` returns when the post is new), and the version
     * of a form is the ID of its latest revision.
     */
    public function ensureRevision(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        $latest = wp_get_latest_revision_id_and_total_count($post);
        if (!is_wp_error($latest) && $latest['latest_id'] === 0) {
            wp_save_post_revision($postId);
        }
    }

    private function latestRevision(int $postId): int
    {
        $latest = wp_get_latest_revision_id_and_total_count($postId);
        if (is_wp_error($latest) || $latest['latest_id'] === 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_revision form ' . $postId);
        }

        return $latest['latest_id'];
    }
}
