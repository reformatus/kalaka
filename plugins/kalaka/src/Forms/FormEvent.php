<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The event of a form is set when the form is created and fixed once the form has fields (owner ruling
 * 2026-11-13, B2(a)): keys are derived across the event's forms, so a form with fields cannot move to
 * another event. A save that changes the event is refused before anything is stored when the stored
 * content or the incoming content holds a field block; a form without fields may still change its
 * event, and fields may be added in a later save.
 *
 * A save learns the event the way `DefinitionCheck` learns the placement: from the REST request (the REST
 * controller saves meta only after the post, so it is remembered here for this request's
 * `wp_insert_post_data`), else from `meta_input`, else the stored one. Every change of the setting outside
 * a save goes through the meta filters below, which refuse a change of a form with fields; the one gap is
 * code that sets an event on a form that had none with `update_post_meta()` directly, because the REST
 * create sets the event the same way, after the post with its fields exists.
 */
final class FormEvent
{
    /** The problem code of a refused event change; its subject is the setting. */
    public const PROBLEM = 'event_fixed';

    /** The value `requested()` returns when the save does not set the event. */
    public const NOT_GIVEN = false;

    /** @var array<int, mixed> the event a REST save sets, by post ID (0: a new form) */
    private static array $fromRest = [];

    /** @var array<int, true> forms whose event change this request's save refused */
    private static array $refused = [];

    /** Remembers the event a REST save sets, for the `wp_insert_post_data` of the same save. */
    public static function rememberRest(int $id, mixed $requested): void
    {
        self::$fromRest[$id] = $requested;
    }

    /**
     * What the save sets: the REST request's value, else `meta_input`'s, else `NOT_GIVEN`.
     *
     * @param array<string, mixed> $postarr
     */
    public static function requested(int $id, array $postarr): mixed
    {
        if (array_key_exists($id, self::$fromRest)) {
            return self::$fromRest[$id];
        }
        $meta = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];

        return array_key_exists(FormPosts::META_EVENT_ID, $meta) ? $meta[FormPosts::META_EVENT_ID] : self::NOT_GIVEN;
    }

    /** Forgets what a REST save remembered, once its `wp_insert_post_data` is done. */
    public static function forget(int $id): void
    {
        unset(self::$fromRest[$id]);
    }

    /**
     * The event the form has after the save, and whether the save's change of it is refused. It judges the
     * stored form and the incoming content together, before anything is stored: a change is refused when
     * the stored form has fields, or when the incoming content has fields and the form already had an event.
     * Setting the first event of a form whose stored content has no fields (a new form, or the block
     * editor's auto-draft on its first save) is its creation, and allowed.
     *
     * @param ?string $incoming the content the save stores, or null when it keeps the stored one
     * @return array{?int, bool}
     */
    public static function resolve(int $id, mixed $requested, ?string $incoming = null): array
    {
        $stored = self::stored($id);
        if ($requested === self::NOT_GIVEN) {
            return [$stored, false];
        }
        $event = self::eventId($requested);
        if ($event === $stored) {
            return [$stored, false];
        }
        $incomingFields = $incoming !== null && FieldBlocks::any($incoming, Contributions::current());
        if (self::hasFields($id) || ($stored !== null && $incomingFields)) {
            return [$stored, true];
        }

        return [$event, false];
    }

    /** Refuses the event setting's write that follows this request's save of the form. */
    public static function refuse(int $id): void
    {
        self::$refused[$id] = true;
    }

    /** The stored event of a form, or null. */
    public static function stored(int $id): ?int
    {
        return $id > 0 ? self::eventId(get_post_meta($id, FormPosts::META_EVENT_ID, true)) : null;
    }

    /** A positive event ID, or null for anything else (unset, 0, empty). */
    public static function eventId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1 ? (int) $value : null;
    }

    /** Whether the stored form holds a field block. */
    public static function hasFields(int $id): bool
    {
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof \WP_Post
            && $post->post_type === FormPosts::POST_TYPE
            && FieldBlocks::any($post->post_content, Contributions::current());
    }

    /**
     * `add_post_metadata` and `update_post_metadata`: refuses a change of a form's event once it has fields.
     *
     * @internal
     */
    public static function onWrite(mixed $check, int $objectId, string $metaKey, mixed $value): mixed
    {
        if ($metaKey !== FormPosts::META_EVENT_ID || get_post_type($objectId) !== FormPosts::POST_TYPE) {
            return $check;
        }

        return self::allows($objectId, self::eventId($value)) ? $check : false;
    }

    /**
     * `delete_post_metadata`: the same for removing the setting.
     *
     * @internal
     */
    public static function onDelete(mixed $check, int $objectId, string $metaKey): mixed
    {
        if ($metaKey !== FormPosts::META_EVENT_ID || get_post_type($objectId) !== FormPosts::POST_TYPE) {
            return $check;
        }

        return self::allows($objectId, null) ? $check : false;
    }

    private static function allows(int $id, ?int $event): bool
    {
        $stored = self::stored($id);
        if ($event === $stored) {
            return true;
        }
        $refused = isset(self::$refused[$id]);
        unset(self::$refused[$id]);
        if (!$refused && ($stored === null || !self::hasFields($id))) {
            return true;
        }
        if (!$refused) {
            DefinitionCheck::notify(self::PROBLEM, FormPosts::META_EVENT_ID);
        }

        return false;
    }
}
