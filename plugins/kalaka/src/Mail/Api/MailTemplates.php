<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/**
 * The "new template" of a sending point (küldési pont; owner 2026-11-27, walkthrough 8): a draft `kalaka_mail`
 * post of the event with an empty body, assigned to the point, which is switched off. The post is made here,
 * outside any use case (WordPress data is never written in a transaction), the way `wp kalaka action-mail body`
 * makes one: the actor passes the use case's access check first, the post is made, and the assignment goes
 * through `UpdateActionMail`, which switches the point off as any change of its template does. Only the access
 * check comes before the post: the use case's own refusal needs the post's facts, so it comes after, and then the
 * post is removed again. A refused assignment (the "registered" mail of an event with accounts stays on, and a
 * draft cannot be its template: `PointTemplateUnpublished`) so makes the post for a moment and leaves none.
 *
 * `createDraft()` makes the same draft without a point (the screen's unassigned templates): no point changes and
 * nothing switches off. Both entry points are outside the abilities, as `wp kalaka action-mail body` is: the post
 * is WordPress data, which no use case's transaction writes.
 * [UNAPPROVED N1184]
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

        $made = $this->draft($eventId);
        try {
            $this->settings->update(
                ['event_id' => $eventId, 'mail' => $action->value, 'body_id' => $made['id']],
                $actor
            );
        } catch (\Throwable $e) {
            wp_delete_post($made['id'], true);
            throw $e;
        }

        return $made;
    }

    /**
     * An empty draft template of the event, assigned to no point: no point's template changes and no point
     * switches off. Full access only, like the mail screens' other writes and reads; a point's own template is
     * `createFor()`. [UNAPPROVED N1184]
     *
     * @return array{id: int, edit_url: string} the new post and its edit URL
     * @throws NotFound when there is no such event
     * @throws PermissionDenied without full access
     * @throws SystemFailure when WordPress does not make the post
     */
    public function createDraft(int $eventId, Actor $actor): array
    {
        $this->events->find($eventId) ?? throw new NotFound();
        if (!AdminAccess::isFull($actor)) {
            throw new PermissionDenied();
        }

        return $this->draft($eventId);
    }

    /**
     * The post itself: an empty draft `kalaka_mail` post of the event, its event in the meta.
     *
     * @return array{id: int, edit_url: string}
     * @throws SystemFailure when WordPress does not make the post
     */
    private function draft(int $eventId): array
    {
        $id = wp_insert_post([
            'post_type' => MailPosts::POST_TYPE,
            'post_status' => 'draft',
            'post_content' => '',
            'meta_input' => [MailPosts::META_EVENT_ID => $eventId],
        ], true);
        if (!is_int($id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a log message, never output
            throw new SystemFailure(null, new \RuntimeException('The template post was not made.'));
        }

        return ['id' => $id, 'edit_url' => (string) get_edit_post_link($id, 'raw')];
    }
}
