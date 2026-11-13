<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Reads the `kalaka_mail` posts: the bodies of mails, edited in the block editor (mail.md,
 * "Content"). Each belongs to one event (`kalaka_event_id`). It reads WordPress posts, so it runs before or
 * after a use case's transaction, or in a use case only to check a post an input names.
 */
final class MailPosts
{
    public const POST_TYPE = 'kalaka_mail';

    public const META_EVENT_ID = 'kalaka_event_id';

    /** The event a `kalaka_mail` post belongs to; null for any other post or one with no valid event. */
    public function eventOf(int $id): ?int
    {
        $post = $id > 0 ? get_post($id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return null;
        }
        $eventId = get_post_meta($post->ID, self::META_EVENT_ID, true);
        if (!is_scalar($eventId) || preg_match('/\A[1-9][0-9]*\z/', (string) $eventId) !== 1) {
            return null;
        }

        return (int) $eventId;
    }

    /**
     * The published document of the event, as it is now, with its version.
     *
     * @throws SystemFailure when it is no published `kalaka_mail` post of the event (problem `mail_document`)
     *         or has no revision (problem `no_revision`)
     */
    public function published(int $id, int $eventId): MailDocument
    {
        $post = get_post($id);
        if (
            !$post instanceof \WP_Post
            || $post->post_status !== 'publish'
            || $this->eventOf($id) !== $eventId
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('mail_document ' . $id);
        }

        return new MailDocument($post->ID, $post->post_title, $post->post_content, $this->latestRevision($post->ID));
    }

    /**
     * Saves the first revision of a mail body that has none: WordPress saves none on insert, and a
     * mail's version is a revision ID.
     *
     * @internal the `wp_after_insert_post` callback
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

    /** The latest saved revision, never an autosave. */
    private function latestRevision(int $postId): int
    {
        $revisions = get_posts([
            'post_parent' => $postId,
            'post_type' => 'revision',
            'post_status' => 'inherit',
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        foreach ($revisions as $revision) {
            if (wp_is_post_autosave($revision) === false) {
                return $revision;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
        throw new SystemFailure('no_revision mail ' . $postId);
    }
}
