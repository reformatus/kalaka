<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Checks a form when it is saved, so a published form never goes from working to broken through an edit
 * and a form never breaks its event's participant fields. Three checks, in this order:
 *
 * - the event (`FormEvent`): a form with fields keeps its event (`event_fixed`);
 * - the keys (`SharedKeyCheck`): no two blocks of the form hold the same key (`duplicate_key`), and against
 *   the event's participant fields a shared key has the same type (`type_clash`) and no form holds a
 *   `system` field's key (`system_field`). This runs on every save but an autosave, drafts included,
 *   because every form of the event declares its fields;
 * - when the form is saved as published or scheduled, the definition check `DefinitionParser` runs on the
 *   live form (keys, options, unknown blocks, the slot's markings, and whatever the parser checks later
 *   on). A draft is not parsed: it can be half built. A form whose placement is not set yet is not parsed
 *   either: it cannot be used anyway. A scheduled form is checked again when WordPress publishes it
 *   (`onPublishFuture`), so it never publishes itself broken.
 *
 * Each runs over the content as it will be stored (keys and option values derived first, across the event).
 *
 * The REST save (the block editor) is refused with a 400 before anything is written, checked with the
 * placement and the event the request sets, if any; any other save path (code, WP-CLI, a revision restore)
 * is vetoed gracefully in `wp_insert_post_data`, checked with what it sets in `meta_input`, else the stored
 * settings: nothing ever throws out of a core filter. An
 * autosave is stored as a revision and is never a version (`FormPosts`), so it is not checked.
 */
final class DefinitionCheck
{
    /**
     * The placement the REST check used, by post ID, for this request's `wp_insert_post_data` that
     * follows it: the REST controller saves the meta only after the post, so the stored placement is
     * still the old one there.
     *
     * @var array<int, FormPlacement>
     */
    private static array $restPlacements = [];

    /** The refusal: `{problem}` is the problem code, `{subject}` the field key or block. */
    public const MESSAGE = 'Az űrlapot így nem lehet menteni: %1$s („%2$s”).';

    /** The statuses that are, or will become, live: a scheduled form publishes itself later. */
    private const LIVE = ['publish', 'future'];

    /** The transient that holds a vetoed save's message, per user: this prefix and the user ID. */
    public const NOTICE = 'kalaka_form_refused_';

    /**
     * The problem of a content as a published form of that placement in that event, or null when it has none.
     *
     * @return array{string, string}|null the problem code and its subject (a key, a block or the form)
     */
    public static function problem(string $content, int $formId, FormPlacement $placement, ?int $eventId = null): ?array
    {
        return self::problemOf($content, $formId, $placement, $eventId, true);
    }

    /**
     * The readable refusals of the participant-field checks, `%s` the field key; every other problem keeps
     * the generic `MESSAGE` with its code.
     */
    public const MESSAGES = [
        FormEvent::PROBLEM => 'Az űrlapot így nem lehet menteni: már vannak mezői, ezért az eseménye nem'
            . ' módosítható.',
        SharedKeyCheck::DUPLICATE_KEY => 'Az űrlapot így nem lehet menteni: két mezőjének ugyanaz az azonosítója'
            . ' („%s”).',
        SharedKeyCheck::TYPE_CLASH => 'Az űrlapot így nem lehet menteni: az egyik mező azonosítóját az esemény egy más'
            . ' típusú résztvevői mezője használja („%s”).',
        SharedKeyCheck::SYSTEM_FIELD => 'Az űrlapot így nem lehet menteni: az egyik mező azonosítója egy olyan'
            . ' résztvevői mezőé, amelyet a rendszer tölt ki („%s”).',
        SharedKeyCheck::EXTENSION_FIELD => 'Az űrlapot így nem lehet menteni: az egyik mező azonosítója egy olyan'
            . ' résztvevői mezőé, amelyet egy bővítmény tölt ki („%s”).',
    ];

    /** The refusal's text for a problem. */
    public static function message(string $code, string $subject): string
    {
        $text = self::MESSAGES[$code] ?? null;
        if ($text === null) {
            return sprintf(self::MESSAGE, $code, $subject);
        }

        return str_contains($text, '%s') ? sprintf($text, $subject) : $text;
    }

    /**
     * Keeps a vetoed save's refusal for the saving user, shown on their next admin page (`notice()`).
     *
     * @internal
     */
    public static function notify(string $code, string $subject): void
    {
        $user = get_current_user_id();
        if ($user > 0) {
            set_transient(self::NOTICE . $user, self::message($code, $subject), HOUR_IN_SECONDS);
        }
    }

    /**
     * `rest_pre_insert_kalaka_form`: refuses a REST save that would change the event of a form with fields,
     * break the event's participant fields or publish a broken definition.
     *
     * @param \stdClass $prepared the post as the REST controller prepared it
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function onRestInsert(\stdClass $prepared, \WP_REST_Request $request): \stdClass|\WP_Error
    {
        $id = isset($prepared->ID) && is_int($prepared->ID) ? $prepared->ID : 0;
        $stored = $id > 0 ? get_post($id) : null;
        $status = is_string($prepared->post_status ?? null)
            ? $prepared->post_status
            : ($stored instanceof \WP_Post ? $stored->post_status : 'draft');
        $content = is_string($prepared->post_content ?? null)
            ? $prepared->post_content
            : ($stored instanceof \WP_Post ? $stored->post_content : '');
        $meta = $request->get_param('meta');
        $meta = is_array($meta) ? $meta : [];
        $placement = self::placement($meta[FormPosts::META_PLACEMENT] ?? null, $id);
        $requested = array_key_exists(FormPosts::META_EVENT_ID, $meta)
            ? $meta[FormPosts::META_EVENT_ID]
            : FormEvent::NOT_GIVEN;
        [$eventId, $fixed] = FormEvent::resolve($id, $requested, $content);

        $problem = $fixed
            ? [FormEvent::PROBLEM, FormPosts::META_EVENT_ID]
            : self::problemOf($content, $id, $placement, $eventId, in_array($status, self::LIVE, true));
        if ($problem === null) {
            if ($placement !== null) {
                self::$restPlacements[$id] = $placement;
            }
            FormEvent::rememberRest($id, $requested);

            return $prepared;
        }

        return new \WP_Error('kalaka_form_definition', self::message(...$problem), ['status' => 400]);
    }

    /**
     * `wp_insert_post_data`, after the keys are derived: the backstop for every other save path (code,
     * WP-CLI, WordPress's own revision restore). It never throws out of the core filter: a refused save
     * is vetoed gracefully.
     *
     * - A change of a form's event is refused: the setting and the content keep their stored values (the
     *   new content's keys were derived for the stored event).
     * - Content whose keys break the event's participant fields is not stored: a stored form keeps its
     *   stored content, a new one is saved empty as a draft.
     * - Content that would publish a broken definition: a published form keeps its stored content; any
     *   other form keeps the new content but not the published status.
     *
     * Why is kept for the saving user and shown as an admin notice on their next admin page (`notice()`).
     *
     * @param array<string, mixed> $data the post's fields, slashed
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function onInsert(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? null) !== FormPosts::POST_TYPE) {
            return $data;
        }
        $id = is_int($postarr['ID'] ?? null) ? $postarr['ID'] : 0;
        $fromRest = self::$restPlacements[$id] ?? null;
        unset(self::$restPlacements[$id]);
        $requested = FormEvent::requested($id, $postarr);
        FormEvent::forget($id);
        $status = $data['post_status'] ?? null;
        if (!is_string($data['post_content'] ?? null) || in_array($status, ['auto-draft', 'trash'], true)) {
            return $data;
        }

        $problem = null;
        $stored = $id > 0 ? get_post($id) : null;
        [$eventId, $fixed] = FormEvent::resolve($id, $requested, wp_unslash($data['post_content']));
        if ($fixed) {
            // Nothing the change would have touched is stored: the setting and the content stay as stored.
            FormEvent::refuse($id);
            $problem = [FormEvent::PROBLEM, FormPosts::META_EVENT_ID];
            if ($stored instanceof \WP_Post) {
                $data['post_content'] = wp_slash($stored->post_content);
            }
        }

        $keys = SharedKeyCheck::problem(wp_unslash($data['post_content']), $eventId, Contributions::current());
        if ($keys !== null) {
            $problem ??= $keys;
            if ($stored instanceof \WP_Post) {
                $data['post_content'] = wp_slash($stored->post_content);
            } else {
                $data['post_content'] = '';
                $data['post_status'] = 'draft';
            }
        }

        if (in_array($data['post_status'] ?? null, self::LIVE, true)) {
            $meta = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];
            $placement = $fromRest ?? self::placement($meta[FormPosts::META_PLACEMENT] ?? null, $id);
            $broken = $placement !== null
                ? self::problemOf(wp_unslash((string) $data['post_content']), $id, $placement, $eventId, true)
                : null;
            if ($broken !== null) {
                $problem ??= $broken;
                if ($stored instanceof \WP_Post && in_array($stored->post_status, self::LIVE, true)) {
                    $data['post_content'] = wp_slash($stored->post_content);
                } else {
                    $data['post_status'] = $stored instanceof \WP_Post ? $stored->post_status : 'draft';
                }
            }
        }

        if ($problem !== null) {
            self::notify(...$problem);
        }

        return $data;
    }

    /**
     * `publish_future_post`, before WordPress publishes a scheduled form (priority 5; core's
     * `check_and_publish_future_post()` runs at 10): a scheduled form whose definition is broken now
     * (e.g. a field type that is gone) goes back to draft instead of live, and its author gets the notice.
     * Core then finds it no longer scheduled and publishes nothing. Nothing throws out of the hook: what
     * escapes is logged (`Boundary::background`).
     */
    public static function onPublishFuture(int $postId): void
    {
        Boundary::background('checking a scheduled form', static function () use ($postId): void {
            $post = get_post($postId);
            if (
                !$post instanceof \WP_Post
                || $post->post_type !== FormPosts::POST_TYPE
                || $post->post_status !== 'future'
            ) {
                return;
            }
            $placement = self::placement(null, $postId);
            $problem = $placement !== null
                ? self::problem($post->post_content, $postId, $placement, FormEvent::stored($postId))
                : null;
            if ($problem === null) {
                return;
            }
            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
            $author = (int) $post->post_author;
            if ($author > 0) {
                set_transient(self::NOTICE . $author, self::message(...$problem), DAY_IN_SECONDS);
            }
        });
    }

    /** `admin_notices`: the saving user's vetoed save, once. */
    public static function notice(): void
    {
        $key = self::NOTICE . get_current_user_id();
        $message = get_transient($key);
        if (!is_string($message)) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * The first problem of a content: the keys against the event's participant fields, then, when it is
     * to be published with a placement, the definition.
     *
     * @return array{string, string}|null
     */
    private static function problemOf(
        string $content,
        int $formId,
        ?FormPlacement $placement,
        ?int $eventId,
        bool $published,
    ): ?array {
        $contributions = Contributions::current();
        $content = (new FieldKeys())->derive(
            $content,
            $contributions,
            SharedKeyCheck::takenKeys($eventId),
            FieldKeys::stored($formId),
        );
        $problem = SharedKeyCheck::problem($content, $eventId, $contributions);
        if ($problem !== null || !$published || $placement === null) {
            return $problem;
        }
        try {
            $form = new Form($formId, 0, $placement, null, false, 0);
            (new DefinitionParser())->parse($form, 0, $content, $contributions);
        } catch (SystemFailure $e) {
            return self::split($e);
        }

        return null;
    }

    /** The placement being saved, else the stored one, else null. */
    private static function placement(mixed $given, int $id): ?FormPlacement
    {
        if (is_string($given) && $given !== '') {
            return FormPlacement::tryFrom($given);
        }
        $stored = $id > 0 ? get_post_meta($id, FormPosts::META_PLACEMENT, true) : '';

        return is_string($stored) ? FormPlacement::tryFrom($stored) : null;
    }

    /**
     * The code and the subject of a parser's `SystemFailure`, whose context is `<code> <subject>`.
     *
     * @return array{string, string}
     */
    public static function split(SystemFailure $e): array
    {
        $default = (new SystemFailure())->getMessage();
        $context = trim(str_starts_with($e->getMessage(), $default)
            ? substr($e->getMessage(), strlen($default))
            : $e->getMessage());
        $parts = explode(' ', $context, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
