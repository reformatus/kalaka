<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Body\MailValues;

/**
 * What `UpdateActionMail` needs to know about a body, read from WordPress before its transaction (WordPress
 * data is never read inside one): its event, whether it is published now, and whether it reads the
 * set-password link (`account.password_link`, S15).
 */
final readonly class MailBody
{
    public function __construct(
        public int $id,
        public ?int $eventId,
        public bool $published,
        public bool $readsPasswordLink = false,
    ) {
    }

    /** Reads the facts of a post; it runs outside any use case. */
    public static function read(int $id, MailPosts $posts = new MailPosts()): self
    {
        $post = get_post($id);
        $content = $post instanceof \WP_Post ? $post->post_content : '';

        return new self($id, $posts->eventOf($id), $posts->isPublished($id), MailValues::readsPasswordLink($content));
    }
}
