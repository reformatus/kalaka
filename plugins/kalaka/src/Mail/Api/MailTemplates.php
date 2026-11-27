<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/**
 * The "new template" of a sending point (küldési pont; owner 2026-11-27, walkthrough 8): a draft `kalaka_mail`
 * post of the event with an empty body, assigned to the point, which is switched off. The post is made here,
 * outside any use case (WordPress data is never written in a transaction), the way `wp kalaka action-mail body`
 * makes one: the actor passes the use case's access check first, the post is made, and the assignment goes
 * through `UpdateActionMail`, which switches the point off as any change of its template does; if that is
 * refused the post is removed again. Nothing is made for a point that already holds a template the use case
 * would not let go (the "registered" mail of an event with accounts stays on, and a draft cannot be its template).
 */
final class MailTemplates
{
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ActionMailSettings $settings = new ActionMailSettings(),
    ) {
    }

    /**
     * @return array{id: int, edit_url: string} the new post and its edit URL
     * @throws NotFound when there is no such event
     * @throws InvalidInput for a mail that is none of the catalogue's
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the access check or the assignment throws
     * @throws SystemFailure when WordPress does not make the post
     */
    public function createFor(int $eventId, string $mail, Actor $actor): array
    {
        $this->events->find($eventId) ?? throw new NotFound();
        $action = ActionMail::tryFrom($mail) ?? throw new InvalidInput();
        $input = ['event_id' => $eventId, 'mail' => $action->value, 'body_id' => null];
        AdminAccess::check(new UpdateActionMail(), $input, $actor);

        $id = wp_insert_post([
            'post_type' => MailPosts::POST_TYPE,
            'post_status' => 'draft',
            'post_content' => '',
            'meta_input' => [MailPosts::META_EVENT_ID => $eventId],
        ], true);
        if (!is_int($id)) {
            throw new SystemFailure(null, new \RuntimeException('The template post was not made.'));
        }
        try {
            $this->settings->update(['event_id' => $eventId, 'mail' => $action->value, 'body_id' => $id], $actor);
        } catch (\Throwable $e) {
            wp_delete_post($id, true);
            throw $e;
        }

        return ['id' => $id, 'edit_url' => (string) get_edit_post_link($id, 'raw')];
    }
}
