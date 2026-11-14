<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Declares an event's participant fields after one of its forms changes. A post save is
 * WordPress's write, so it never touches Kaláka's tables itself: once the post and its settings are stored
 * (`wp_after_insert_post`, which the REST controller fires after the meta too, and which a trash or a
 * restore fires as well), or once a form is deleted for good (`deleted_post`), the forms of the event are
 * read here, outside any transaction, and `ParticipantFields::declare()` runs `DeclareParticipantFields` as
 * an audited use case, as the saving user (the CLI, or the system when nobody is logged in).
 *
 * The input is the union of every form of the event that is not trashed, so a run that failed, or two forms
 * saved at the same moment (the later insert of a new key fails on the primary key), is healed by the next
 * change of any form of the event. A key some form holds with another type than the event's field (only a
 * form written around the save check) never stops the rest: that form's field is left out of the union, the
 * use case skips any key that still clashes, each such key is logged, and a published form holding one is
 * closed and named in the broken-form warning (`Forms::definition()`, `BrokenForms`). Nothing escapes the
 * core action: a failure is logged.
 */
final class ParticipantFieldSync
{
    /** @var array<int, int> the event of a form being deleted, by post ID, kept before its meta goes */
    private static array $deleting = [];

    /** `wp_after_insert_post` */
    public static function onPostChange(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== FormPosts::POST_TYPE || $post->post_status === 'auto-draft') {
            return;
        }
        $eventId = FormEvent::stored($postId);
        if ($eventId !== null) {
            self::sync($eventId, $post);
        }
    }

    /** `before_delete_post`: keeps the event of a form being deleted for good. */
    public static function beforeDelete(int $postId, mixed $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : get_post_type($postId);
        $eventId = $type === FormPosts::POST_TYPE ? FormEvent::stored($postId) : null;
        if ($eventId !== null) {
            self::$deleting[$postId] = $eventId;
        }
    }

    /** `deleted_post`: the event's list without the deleted form. */
    public static function afterDelete(int $postId): void
    {
        $eventId = self::$deleting[$postId] ?? null;
        unset(self::$deleting[$postId]);
        if ($eventId !== null) {
            self::sync($eventId, null);
        }
    }

    private static function sync(int $eventId, ?\WP_Post $saved): void
    {
        $what = 'participant fields of event ' . $eventId;
        Boundary::background($what, static function () use ($eventId, $saved, $what): void {
            [$fields, $conflicts] = self::fields($eventId, $saved);
            $skipped = (new ParticipantFields())->declare($eventId, $fields, self::actor());
            foreach ($skipped as $key) {
                if (str_contains(implode("\n", $conflicts), 'key ' . $key . ' of form ')) {
                    continue; // Already named with its form.
                }
                $conflicts[] = 'key ' . $key . ' (the event field has another type, or forms may not hold it)';
            }
            if ($conflicts !== []) {
                // Handled here (the rest of the list is declared), so only logged.
                Boundary::report(
                    $what,
                    new \UnexpectedValueException('Skipped clashing keys: ' . implode('; ', $conflicts)),
                );
            }
        });
    }

    /**
     * The fields every form of the event holds, the saved form's first and marked `latest`, and the fields
     * left out because they clash: with the event's stored field of that key, or, for a key not stored yet,
     * with the form that holds it first. A field that clashes with the stored field is still passed on with
     * its own type when no other form holds the key, so the stored field counts as held (not retired); the
     * use case skips it.
     *
     * @return array{
     *     list<array{
     *         key: string,
     *         type: string,
     *         title: string,
     *         options: ?list<array{value: string, label: string}>,
     *         latest: bool,
     *     }>,
     *     list<string>,
     * }
     */
    public static function fields(int $eventId, ?\WP_Post $saved): array
    {
        $contributions = Contributions::current();
        $holding = $saved !== null && in_array($saved->post_status, FormPosts::HOLDING_STATUSES, true);
        $forms = $holding ? [$saved] : [];
        foreach ((new FormPosts())->ofEvent($eventId) as $post) {
            if ($saved === null || $post->ID !== $saved->ID) {
                $forms[] = $post;
            }
        }
        $types = [];
        foreach ((new ParticipantFields())->of($eventId, true) as $field) {
            $types[$field->key] = $field->type;
        }

        $union = [];
        $conflicts = [];
        $clashing = [];
        foreach ($forms as $form) {
            $latest = $saved !== null && $form->ID === $saved->ID;
            foreach (FieldBlocks::of($form->post_content, $contributions) as $key => $field) {
                $type = $types[$key] ?? $union[$key]['type'] ?? $field['type'];
                if ($field['type'] !== $type) {
                    $conflicts[] = 'key ' . $key . ' of form ' . $form->ID
                        . ' (' . $field['type'] . ', not ' . $type . ')';
                    if (isset($types[$key])) {
                        // A form still holds the stored field's key: passed on, so the field is not retired;
                        // the use case skips it.
                        $clashing[$key] ??= ['key' => $key] + $field + ['latest' => $latest];
                    }
                    continue;
                }
                $known = $union[$key] ?? null;
                if ($known === null) {
                    $union[$key] = ['key' => $key] + $field + ['latest' => $latest];
                    continue;
                }
                if ($field['options'] === null) {
                    continue;
                }
                // Another form holds the same choice field: its options join the union, the labels as known.
                $values = array_column($known['options'] ?? [], 'value');
                foreach ($field['options'] as $option) {
                    if (!in_array($option['value'], $values, true)) {
                        $known['options'][] = $option;
                    }
                }
                $union[$key] = $known;
            }
        }

        $union += $clashing;

        return [array_values($union), $conflicts];
    }

    private static function actor(): Actor
    {
        $user = get_current_user_id();
        if (defined('WP_CLI') && WP_CLI) {
            return new CliActor($user > 0 ? $user : null);
        }

        return $user > 0 ? new UserActor($user) : new SystemActor();
    }
}
