<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Checks a form's definition when it is saved as published, so a published form never goes from working
 * to broken through an edit. The check is the one `DefinitionParser` runs on the live form (keys,
 * options, unknown blocks, the slot's markings, and whatever the parser checks later on), over the
 * content as it will be stored (keys and option values derived first). A draft is not checked: it can be
 * half built. A form whose placement is not set yet is not checked either: it cannot be used anyway.
 *
 * The REST save (the block editor) is refused with a 400 before anything is written, checked with the
 * placement the request sets, if any; any other save path (code, WP-CLI, a revision restore) is vetoed
 * gracefully in `wp_insert_post_data`, checked with the placement it sets in `meta_input`, else the
 * stored one: nothing ever throws out of a core filter. An
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

    /** The transient that holds a vetoed save's message, per user: this prefix and the user ID. */
    public const NOTICE = 'kalaka_form_refused_';

    /**
     * The problem of a content as a published form of that placement, or null when it has none.
     *
     * @return array{string, string}|null the problem code and its subject (a key, a block or the form)
     */
    public static function problem(string $content, int $formId, FormPlacement $placement): ?array
    {
        $contributions = Contributions::current();
        $content = (new FieldKeys())->derive($content, $contributions);
        try {
            $form = new Form($formId, 0, $placement, null, false, 0);
            (new DefinitionParser())->parse($form, 0, $content, $contributions);
        } catch (SystemFailure $e) {
            return self::split($e);
        }

        return null;
    }

    /** The refusal's text for a problem. */
    public static function message(string $code, string $subject): string
    {
        return sprintf(self::MESSAGE, $code, $subject);
    }

    /**
     * `rest_pre_insert_kalaka_form`: refuses a REST save that would publish a broken definition.
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
        $placement = self::placement(is_array($meta) ? ($meta[FormPosts::META_PLACEMENT] ?? null) : null, $id);

        $problem = $status === 'publish' && $placement !== null ? self::problem($content, $id, $placement) : null;
        if ($problem === null) {
            if ($placement !== null) {
                self::$restPlacements[$id] = $placement;
            }

            return $prepared;
        }

        return new \WP_Error('kalaka_form_definition', self::message(...$problem), ['status' => 400]);
    }

    /**
     * `wp_insert_post_data`, after the keys are derived: the backstop for every other save path (code,
     * WP-CLI, WordPress's own revision restore). It never throws out of the core filter: a save that would
     * publish a broken definition is vetoed gracefully. A published form keeps its stored content; any
     * other form keeps the new content but not the published status. Why is kept for the saving user and
     * shown as an admin notice on their next admin page (`notice()`).
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
        if (($data['post_status'] ?? null) !== 'publish' || !is_string($data['post_content'] ?? null)) {
            return $data;
        }
        $meta = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];
        $placement = $fromRest ?? self::placement($meta[FormPosts::META_PLACEMENT] ?? null, $id);
        $problem = $placement !== null ? self::problem(wp_unslash($data['post_content']), $id, $placement) : null;
        if ($problem === null) {
            return $data;
        }

        $stored = $id > 0 ? get_post($id) : null;
        if ($stored instanceof \WP_Post && $stored->post_status === 'publish') {
            $data['post_content'] = wp_slash($stored->post_content);
        } else {
            $data['post_status'] = $stored instanceof \WP_Post ? $stored->post_status : 'draft';
        }
        $user = get_current_user_id();
        if ($user > 0) {
            set_transient(self::NOTICE . $user, self::message(...$problem), HOUR_IN_SECONDS);
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
