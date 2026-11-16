<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\BodyUnusable;

/**
 * A mail body is checked when it is saved as published or scheduled, as a form's definition is
 * (`Forms\DefinitionCheck`): its conditions, bindings and inline values against the mail's keys of its event
 * (`MailValues::types()`, `Values::check()`). A body with a problem is never published.
 *
 * - REST (the block editor): `rest_pre_insert_kalaka_mail` refuses the save with a 400 before anything is
 *   stored.
 * - Any other path (code, WP-CLI, a revision restore): `wp_insert_post_data` vetoes gracefully, as Forms
 *   does: a published body keeps its stored content, any other stays unpublished; the saving user gets the
 *   refusal as an admin notice on their next admin page. Nothing throws out of a core filter.
 *
 * A body without an event yet is not checked: its keys are not known; it is checked when it gets one and is
 * saved again, and before every claim (`MailRenderer`).
 * // [UNAPPROVED N24] a mail body's save check and its refusal.
 */
final class BodyCheck
{
    public const CODE = 'kalaka_mail_body';

    // [UNAPPROVED N20] the refusal: `%1$s` the problem code, `%2$s` its key; the mirror of Forms' refusal.
    public const MESSAGE = 'A sablon szövegét így nem lehet menteni: %1$s („%2$s”).';

    /** The transient that holds a vetoed save's message, per user: this prefix and the user ID. */
    public const NOTICE = 'kalaka_mail_refused_';

    private const LIVE = ['publish', 'future'];

    /**
     * The event the REST check used, by post ID (0: a new body), for this request's `wp_insert_post_data`
     * that follows it: the REST controller saves the meta only after the post, so the stored event is still
     * the old one there (as `Forms\DefinitionCheck` carries the placement).
     *
     * @var array<int, ?int>
     */
    private static array $restEvents = [];

    /** The problem of a body's content in its event, `<code> <subject>`, or null when it has none. */
    public static function problem(string $content, ?int $eventId): ?string
    {
        if ($eventId === null) {
            return null;
        }
        try {
            (new Values())->check($content, (new MailValues())->types($eventId));
        } catch (SystemFailure $e) {
            return BodyUnusable::of($e)->problem;
        }

        return null;
    }

    /** The refusal's text for a problem. */
    public static function message(string $problem): string
    {
        $parts = explode(' ', $problem, 2);

        return sprintf(self::MESSAGE, $parts[0], $parts[1] ?? '');
    }

    /**
     * `rest_pre_insert_kalaka_mail`.
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
        $stored = $id > 0 ? get_post($id) : null;
        $status = is_string($prepared->post_status ?? null)
            ? $prepared->post_status
            : ($stored instanceof \WP_Post ? $stored->post_status : 'draft');
        if (!in_array($status, self::LIVE, true)) {
            return $prepared;
        }
        $content = is_string($prepared->post_content ?? null)
            ? $prepared->post_content
            : ($stored instanceof \WP_Post ? $stored->post_content : '');
        $meta = $request->get_param('meta');
        $event = is_array($meta) && array_key_exists(MailPosts::META_EVENT_ID, $meta)
            ? self::eventId($meta[MailPosts::META_EVENT_ID])
            : (new MailPosts())->eventOf($id);
        $problem = self::problem($content, $event);
        if ($problem !== null) {
            return new \WP_Error(self::CODE, self::message($problem), ['status' => 400]);
        }
        self::$restEvents[$id] = $event;

        return $prepared;
    }

    /**
     * `wp_insert_post_data`: the graceful veto of any other save path.
     *
     * @internal
     * @param array<string, mixed> $data the post's fields, slashed
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function onInsert(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? null) !== MailPosts::POST_TYPE) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        $fromRest = array_key_exists($id, self::$restEvents);
        $restEvent = self::$restEvents[$id] ?? null;
        unset(self::$restEvents[$id]);
        if (!in_array($data['post_status'] ?? null, self::LIVE, true) || !is_string($data['post_content'] ?? null)) {
            return $data;
        }
        $meta = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];
        $event = match (true) {
            $fromRest => $restEvent,
            array_key_exists(MailPosts::META_EVENT_ID, $meta) => self::eventId($meta[MailPosts::META_EVENT_ID]),
            default => (new MailPosts())->eventOf($id),
        };
        $problem = self::problem(wp_unslash($data['post_content']), $event);
        if ($problem === null) {
            return $data;
        }
        $stored = $id > 0 ? get_post($id) : null;
        if ($stored instanceof \WP_Post && in_array($stored->post_status, self::LIVE, true)) {
            $data['post_content'] = wp_slash($stored->post_content);
        } else {
            $data['post_status'] = $stored instanceof \WP_Post ? $stored->post_status : 'draft';
        }
        $user = get_current_user_id();
        if ($user > 0) {
            set_transient(self::NOTICE . $user, self::message($problem), HOUR_IN_SECONDS);
        }

        return $data;
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

    private static function eventId(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/\A[1-9][0-9]*\z/', (string) $value) === 1 ? (int) $value : null;
    }
}
