<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Mail\Catalogue\ActionMailRepository;

/**
 * A mail body that a switched-on action mail uses is neither trashed nor deleted, as `Forms\FormDeletion`
 * keeps a form that submissions refer to: the mail would fail at send time.
 *
 * - Every path (code, WP-CLI, wp-admin, emptying the trash) goes through `pre_trash_post` and
 *   `pre_delete_post`: the change is refused, `wp_trash_post()` and `wp_delete_post()` return false, and the
 *   body stays as it is.
 * - The REST controller refuses both before that (`MailRestController::delete_item()`), with
 *   `kalaka_mail_in_use` (409) and `RuleViolation`'s text with the reason `IN_USE`.
 * A body whose mail is switched off may go.
 */
final class MailDeletion
{
    public const CODE = 'kalaka_mail_in_use';

    // [UNAPPROVED N20] the refusal's reason.
    public const IN_USE = 'Ezt a szöveget egy bekapcsolt levél használja.';

    /** `RuleViolation`'s text with the reason. */
    public static function message(): string
    {
        return (new RuleViolation(self::IN_USE))->getMessage();
    }

    /**
     * `pre_trash_post`: null lets the trashing go on, false refuses it.
     *
     * @internal
     */
    public static function onPreTrash(mixed $trash, \WP_Post $post): mixed
    {
        return $post->post_type === MailPosts::POST_TYPE && self::inUse($post->ID) ? false : $trash;
    }

    /**
     * `pre_delete_post`: null lets the deletion go on, false refuses it.
     *
     * @internal
     */
    public static function onPreDelete(mixed $delete, \WP_Post $post): mixed
    {
        return $post->post_type === MailPosts::POST_TYPE && self::inUse($post->ID) ? false : $delete;
    }

    /** Whether a switched-on action mail of the body's event uses the body. */
    public static function inUse(int $postId): bool
    {
        $eventId = (new MailPosts())->eventOf($postId);
        if ($eventId === null) {
            return false;
        }
        foreach ((new ActionMailRepository())->ofEvent($eventId) as $entry) {
            if ($entry->enabled && $entry->bodyId === $postId) {
                return true;
            }
        }

        return false;
    }
}
