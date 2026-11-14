<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Mail\Catalogue\ActionMailRepository;

/**
 * A mail body that a switched-on action mail uses stays usable: it is neither trashed nor deleted, never
 * leaves `publish` and never changes its event, since any of these would fail the mail at send time. It
 * mirrors `Forms\FormDeletion` and the meta filters of `Forms\FormEvent`. A body whose mail is switched
 * off may change freely.
 *
 * - Code, WP-CLI and wp-admin: `pre_trash_post` and `pre_delete_post` refuse (`wp_trash_post()` and
 *   `wp_delete_post()` return false); `wp_insert_post_data` keeps the stored `publish` when a save sets
 *   another status (the save goes on, the status does not change: no notice, so no new text); the meta
 *   filters refuse a change or removal of `kalaka_event_id`.
 * - REST (the block editor): `MailRestController` refuses a delete, and `rest_pre_insert_kalaka_mail` an
 *   update that sets another status or another event, both before anything is stored, with
 *   `kalaka_mail_in_use` (409) and `RuleViolation`'s text with the reason `IN_USE`.
 */
final class BodyInUse
{
    public const CODE = 'kalaka_mail_in_use';

    // [UNAPPROVED N20] the refusal's reason.
    public const IN_USE = 'Ezt a szöveget egy bekapcsolt levél használja.';

    /** `RuleViolation`'s text with the reason. */
    public static function message(): string
    {
        return (new RuleViolation(self::IN_USE))->getMessage();
    }

    /** The REST refusal. */
    public static function error(): \WP_Error
    {
        return new \WP_Error(self::CODE, self::message(), ['status' => 409]);
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

    /**
     * `wp_insert_post_data`: a save of a body in use keeps `publish`.
     *
     * @internal
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function onInsertData(array $data, array $postarr): array
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if (
            $id > 0
            && ($data['post_type'] ?? null) === MailPosts::POST_TYPE
            && ($data['post_status'] ?? null) !== 'publish'
            && get_post_status($id) === 'publish'
            && self::inUse($id)
        ) {
            $data['post_status'] = 'publish';
        }

        return $data;
    }

    /**
     * `rest_pre_insert_kalaka_mail`: an update of a body in use that sets another status or another event is
     * refused before anything is stored.
     *
     * @internal
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function onRestInsert(\stdClass|\WP_Error $prepared, \WP_REST_Request $request): \stdClass|\WP_Error
    {
        if ($prepared instanceof \WP_Error) {
            return $prepared;
        }
        $id = (int) ($prepared->ID ?? 0);
        if ($id <= 0 || !self::inUse($id)) {
            return $prepared;
        }
        $status = $prepared->post_status ?? null;
        $meta = $request->get_param('meta');
        $event = is_array($meta) && array_key_exists(MailPosts::META_EVENT_ID, $meta)
            ? $meta[MailPosts::META_EVENT_ID]
            : null;
        $stored = (new MailPosts())->eventOf($id);
        if ((is_string($status) && $status !== 'publish') || ($event !== null && (int) $event !== $stored)) {
            return self::error();
        }

        return $prepared;
    }

    /**
     * `add_post_metadata` and `update_post_metadata`: a body in use keeps its event.
     *
     * @internal
     */
    public static function onMetaWrite(mixed $check, int $objectId, string $metaKey, mixed $value): mixed
    {
        if ($metaKey !== MailPosts::META_EVENT_ID || get_post_type($objectId) !== MailPosts::POST_TYPE) {
            return $check;
        }
        $stored = (new MailPosts())->eventOf($objectId);
        $same = is_scalar($value) && $stored !== null && (string) $value === (string) $stored;

        return !$same && self::inUse($objectId) ? false : $check;
    }

    /**
     * `delete_post_metadata`: the same for removing the event.
     *
     * @internal
     */
    public static function onMetaDelete(mixed $check, int $objectId, string $metaKey): mixed
    {
        if ($metaKey !== MailPosts::META_EVENT_ID || get_post_type($objectId) !== MailPosts::POST_TYPE) {
            return $check;
        }

        return self::inUse($objectId) ? false : $check;
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
