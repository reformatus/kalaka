<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Registrations\Api\FormSubmissions;

/**
 * A form that submissions refer to is never deleted permanently (Forms `AGENTS.md`: a submission's
 * version must resolve to its definition for as long as the form exists). Moving it to the trash stays
 * allowed. [UNAPPROVED N15]
 *
 * - Every path (code, WP-CLI, wp-admin, emptying the trash) goes through `pre_delete_post`: the deletion
 *   is refused, `wp_delete_post()` returns false, and the form stays as it is.
 * - The REST controller refuses a forced delete before that (`FormRestController::delete_item()`), with
 *   `kalaka_form_has_submissions` (409) and `RuleViolation`'s approved text.
 */
final class FormDeletion
{
    public const CODE = 'kalaka_form_has_submissions';

    /** `RuleViolation`'s approved text: no new text. */
    public const MESSAGE = 'A művelet nem hajtható végre.';

    /**
     * `pre_delete_post`: null lets the deletion go on, false refuses it.
     *
     * @internal
     */
    public static function onPreDelete(mixed $delete, \WP_Post $post, bool $force): mixed
    {
        if ($post->post_type !== FormPosts::POST_TYPE) {
            return $delete;
        }

        return self::hasSubmissions($post->ID) ? false : $delete;
    }

    /** Whether submissions refer to the form, in its stored event. */
    public static function hasSubmissions(int $formId): bool
    {
        $event = FormEvent::stored($formId);

        return $event !== null && (new FormSubmissions())->exist($event, $formId);
    }
}
