<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\UnknownPlaceholder;
use Kalaka\Forms\Api\Values;
use Kalaka\Forms\Engine\Value\Placeholders;
use Kalaka\Foundation\Api\Entry\SaveVeto;

/**
 * An ordinary post's typed placeholders are checked when it is saved (owner 2026-11-27, walkthrough 8.11 a):
 * every `{{key}}` inside a Kaláka block must be a key the participant side declares for a page today — the
 * event's keys and `registration.code` and `registration.status` (`Api\AfterSubmit::TYPES`); a `{{` outside
 * Kaláka blocks is ordinary text and is left alone (an advisor's reading, not the owner's). The refusal is
 * `kalaka_unknown_placeholder` with T990's text and the keys as typed.
 *
 * - REST (the block editor): `rest_pre_insert_post` and `rest_pre_insert_page` refuse the save with a 422
 *   before anything is stored.
 * - Any other path (code, WP-CLI, a revision restore): `wp_insert_post_data` vetoes gracefully, as
 *   `Forms\DefinitionCheck` and `Mail\BodyCheck` do: a published post keeps its stored content, any other
 *   stays unpublished, and the saving user gets the refusal as an admin notice.
 *
 * Only `post` and `page` are checked: a form and a mail body have their own save checks.
 * [UNAPPROVED N1176]
 */
final class PageCheck
{
    /** The post types whose content is checked: the ordinary ones a participant block may sit on. */
    public const TYPES = ['post', 'page'];

    private const LIVE = ['publish', 'future'];

    /** The transient that holds a vetoed save's message, per user: this prefix and the user ID. */
    public const NOTICE = 'kalaka_page_refused_';

    /**
     * The unknown placeholders of a post's content, as the refusal of its save; null when there are none, or
     * when the content holds no `{{` inside a Kaláka block at all.
     */
    public static function unknown(string $content): ?UnknownPlaceholder
    {
        if (!str_contains($content, '{{')) {
            return null;
        }
        $html = (new Placeholders())->kalakaHtml($content);
        if (!str_contains($html, '{{')) {
            return null;
        }
        $keys = (new Values())->unknownPlaceholders($html, AfterSubmit::TYPES);

        return $keys === [] ? null : new UnknownPlaceholder($keys);
    }

    /**
     * `rest_pre_insert_post` and `rest_pre_insert_page`.
     *
     * @internal
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function onRestInsert(\stdClass|\WP_Error $prepared, \WP_REST_Request $request): \stdClass|\WP_Error
    {
        if ($prepared instanceof \WP_Error) {
            return $prepared;
        }
        $content = is_string($prepared->post_content ?? null) ? $prepared->post_content : '';
        $unknown = self::unknown($content);

        return $unknown === null ? $prepared : $unknown->toWpError();
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
        if (!in_array($data['post_type'] ?? null, self::TYPES, true) || !is_string($data['post_content'] ?? null)) {
            return $data;
        }
        $unknown = self::unknown(wp_unslash($data['post_content']));
        if ($unknown === null) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        $stored = $id > 0 ? get_post($id) : null;
        if ($stored instanceof \WP_Post && in_array($stored->post_status, self::LIVE, true)) {
            $data['post_content'] = wp_slash($stored->post_content);
        } else {
            $data['post_status'] = $stored instanceof \WP_Post ? $stored->post_status : 'draft';
        }
        $message = $unknown->publicMessage();
        $user = get_current_user_id();
        if ($user > 0) {
            set_transient(self::NOTICE . $user, $message, HOUR_IN_SECONDS);
        }
        SaveVeto::report('saving post ' . $id, $message, 'unknown_placeholder ' . implode(', ', $unknown->keys));

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
}
