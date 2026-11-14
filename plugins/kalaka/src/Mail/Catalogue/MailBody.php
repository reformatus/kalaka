<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Mail\Body\MailPosts;

/**
 * What `UpdateActionMail` needs to know about a body, read from WordPress before its transaction (WordPress
 * data is never read inside one): its event and whether it is published now.
 * // [UNAPPROVED N25] the body's facts are read before the run and passed in.
 */
final readonly class MailBody
{
    public function __construct(
        public int $id,
        public ?int $eventId,
        public bool $published,
    ) {
    }

    /** Reads the facts of a post; it runs outside any use case. */
    public static function read(int $id, MailPosts $posts = new MailPosts()): self
    {
        return new self($id, $posts->eventOf($id), $posts->isPublished($id));
    }
}
