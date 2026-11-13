<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\ParticipantField\DeclareParticipantFields;
use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Declares an event's participant fields after one of its forms is saved [UNAPPROVED N5]. A post save is
 * WordPress's write, so it never touches Kaláka's tables itself: once the post and its settings are stored
 * (`wp_after_insert_post`, which the REST controller fires after the meta too), the forms of the event are
 * read here, outside any transaction, and `DeclareParticipantFields` runs as an audited use case, as the
 * saving user (the CLI, or the system when nobody is logged in).
 *
 * The input is the union of every form of the event that is not trashed, so a run that failed, or two forms
 * saved at the same moment (the later insert of a new key fails on the primary key), is healed by the next
 * save of any form of the event. Nothing escapes the core action: a failure is logged.
 */
final class ParticipantFieldSync
{
    /** The statuses of the forms whose fields count: every one but `trash`, `auto-draft` and `inherit`. */
    private const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    /** `wp_after_insert_post` */
    public static function onPostChange(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== FormPosts::POST_TYPE || $post->post_status === 'auto-draft') {
            return;
        }
        $eventId = FormEvent::stored($postId);
        if ($eventId === null) {
            return;
        }
        Boundary::background(
            'participant fields of event ' . $eventId,
            static fn (): mixed => Runner::current()->run(
                new DeclareParticipantFields(),
                ['event_id' => $eventId, 'fields' => self::fields($eventId, $post)],
                self::actor(),
            ),
        );
    }

    /**
     * The fields every form of the event holds, the saved form's first and marked `latest`.
     *
     * @return list<array{
     *     key: string,
     *     type: string,
     *     title: string,
     *     options: ?list<array{value: string, label: string}>,
     *     latest: bool,
     * }>
     */
    public static function fields(int $eventId, \WP_Post $saved): array
    {
        $contributions = Contributions::current();
        $posts = get_posts([
            'post_type' => FormPosts::POST_TYPE,
            'post_status' => self::STATUSES,
            'meta_key' => FormPosts::META_EVENT_ID,
            'meta_value' => (string) $eventId,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
        $forms = in_array($saved->post_status, self::STATUSES, true) ? [$saved] : [];
        foreach ($posts as $post) {
            if ($post->ID !== $saved->ID) {
                $forms[] = $post;
            }
        }

        $union = [];
        foreach ($forms as $form) {
            $latest = $form->ID === $saved->ID;
            foreach (FieldBlocks::of($form->post_content, $contributions) as $key => $field) {
                $known = $union[$key] ?? null;
                if ($known === null) {
                    $union[$key] = ['key' => $key] + $field + ['latest' => $latest];
                    continue;
                }
                if ($known['type'] !== $field['type'] || $field['options'] === null) {
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

        return array_values($union);
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
