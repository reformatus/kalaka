<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Mail\Catalogue\ActionMailRepository;
use Kalaka\Mail\Catalogue\MailBody;

/**
 * Reads an event's mail bodies, the `kalaka_mail` posts of one event (mail.md, "Words", "Content"), for the
 * event's mail page. It reads WordPress posts, so it runs outside any use case. Full access only reads it
 * (the post type is full access's): the caller checks.
 */
final class MailBodies
{
    /** Every status a body can be in but trash and the editor's auto-draft. */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
    ) {
    }

    /**
     * The event's bodies by title, each with the action mail that uses it. One query over the posts and one
     * read of the event's action-mail rows.
     *
     * @return list<MailBodyInfo>
     */
    public function ofEvent(int $eventId): array
    {
        $users = [];
        foreach ($this->mails->ofEvent($eventId) as $entry) {
            if ($entry->bodyId !== null && $entry->action !== null) {
                $users[$entry->bodyId] = $entry->action;
            }
        }

        return array_map(
            fn (\WP_Post $post): MailBodyInfo => $this->info($post, $eventId, $users[$post->ID] ?? null),
            $this->query($eventId)
        );
    }

    /**
     * The event's published bodies as `{id, subject}`: the ones a manual mail may send.
     *
     * @return list<array{id: int, subject: string}>
     */
    public function publishedOf(int $eventId): array
    {
        $published = [];
        foreach ($this->query($eventId, ['publish']) as $post) {
            $published[] = ['id' => $post->ID, 'subject' => $post->post_title];
        }

        return $published;
    }

    /**
     * The titles of some bodies, by ID; a body that is gone is absent.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function subjects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $subjects = [];
        foreach (
            get_posts([
                'post_type' => MailPosts::POST_TYPE,
                'post_status' => 'any',
                'post__in' => $ids,
                'posts_per_page' => count($ids),
                'orderby' => 'none',
                'no_found_rows' => true,
                'suppress_filters' => true,
            ]) as $post
        ) {
            $subjects[$post->ID] = $post->post_title;
        }

        return $subjects;
    }

    /**
     * @param list<string> $statuses
     * @return list<\WP_Post>
     */
    private function query(int $eventId, array $statuses = self::STATUSES): array
    {
        $query = new \WP_Query([
            'post_type' => MailPosts::POST_TYPE,
            'post_status' => $statuses,
            'meta_key' => MailPosts::META_EVENT_ID,
            'meta_value' => $eventId,
            'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'suppress_filters' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        /** @var list<\WP_Post> $posts */
        $posts = array_values($query->posts ?? []);

        return $posts;
    }

    private function info(\WP_Post $post, int $eventId, ?ActionMail $usedBy): MailBodyInfo
    {
        return new MailBodyInfo(
            $post->ID,
            $post->post_title,
            $post->post_status,
            $usedBy,
            Instant::fromStoredOrNull($post->post_modified_gmt),
            admin_url('post.php?post=' . $post->ID . '&action=edit'),
            new MailBody(
                $post->ID,
                $eventId,
                $post->post_status === 'publish',
                MailValues::readsPasswordLink($post->post_content)
            )
        );
    }
}
