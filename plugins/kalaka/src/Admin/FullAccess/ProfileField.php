<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\GrantFullAccess;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The full-access checkbox on the profile and user-edit screens, shown only to a user who has full access
 * themselves. A changed box is checked once WordPress has checked the rest of the form
 * (`user_profile_update_errors`, last): when the form has an error, nothing is done; a refusal (the last
 * holder) is added to WordPress's own profile errors, so nothing of the form is saved, as for WordPress's own
 * fields. Only after WordPress has saved the user (`profile_update`) does `kalaka/grant-full-access` or
 * `kalaka/revoke-full-access` run, as the current user.
 */
final class ProfileField
{
    private const FIELD = 'kalaka_full_access';

    private const SHOWN = 'kalaka_full_access_shown';

    /** @var array<int, bool> The checked changes of this request, by user: whether to give or to take. */
    private static array $pending = [];

    /** `show_user_profile` and `edit_user_profile`. */
    public static function render(\WP_User $user): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html and esc_attr below.
        echo Boundary::render('the full-access field', static function () use ($user): string {
            if (!AdminAccess::userIsFull(get_current_user_id())) {
                return '';
            }

            return '<h2>' . esc_html(Texts::SECTION) . '</h2>'
                . '<table class="form-table" role="presentation"><tr>'
                . '<th scope="row">' . esc_html(Texts::ROW) . '</th>'
                . '<td><input type="hidden" name="' . esc_attr(self::SHOWN) . '" value="1">'
                . '<label for="' . esc_attr(self::FIELD) . '">'
                . '<input type="checkbox" name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '"'
                . ' value="1"'
                . (self::holds($user) ? ' checked="checked"' : '') . '> '
                . esc_html(Texts::CHECKBOX) . '</label>'
                . '<p class="description">' . esc_html(Texts::HELP) . '</p>'
                . '</td></tr></table>';
        });
    }

    /**
     * `user_profile_update_errors`, after every other check: records a changed box for `save()` unless the
     * form has an error or the change would be refused, in which case the refusal is added to the errors.
     */
    public static function check(\WP_Error $errors, bool $update, object $user): void
    {
        $userId = $update && isset($user->ID) ? (int) $user->ID : 0;
        unset(self::$pending[$userId]);
        $wanted = self::change($userId);
        if ($wanted === null || $errors->has_errors()) {
            return;
        }

        try {
            (new FullAccessChange())->check($userId, $wanted);
        } catch (\Throwable $e) {
            self::keepProfileEmailErrors($errors);
            // One message per line, as WordPress shows each error in its own paragraph.
            foreach (explode("\n", Boundary::shown('checking full access', $e)->displayMessage()) as $line) {
                $errors->add(self::FIELD, esc_html($line));
            }

            return;
        }
        self::$pending[$userId] = $wanted;
    }

    /**
     * `profile_update`: runs the change `check()` recorded for this user, once WordPress has saved the
     * user. A refusal here (a change by someone else in between) leaves the box as it is stored, which the
     * screen shows after its reload; it is logged only when it is a failure.
     */
    public static function save(int $userId): void
    {
        if (!array_key_exists($userId, self::$pending)) {
            return;
        }
        $wanted = self::$pending[$userId];
        unset(self::$pending[$userId]);

        try {
            Runner::current()->run(
                $wanted ? new GrantFullAccess() : new RevokeFullAccess(),
                ['user_id' => $userId],
                new UserActor(get_current_user_id())
            );
        } catch (\Throwable $e) {
            Boundary::shown('saving full access', $e);
        }
    }

    /**
     * On one's own profile WordPress checks a new e-mail address before `edit_user()`, in the global
     * `$errors`, and goes on saving with the old address; `edit_user()`'s errors then replace that list.
     * When the refusal stops the save, those messages are carried over, so the screen shows them too.
     */
    private static function keepProfileEmailErrors(\WP_Error $errors): void
    {
        $early = $GLOBALS['errors'] ?? null;
        if (!$early instanceof \WP_Error || $early === $errors) {
            return;
        }
        foreach ($early->get_error_codes() as $code) {
            foreach ($early->get_error_messages($code) as $message) {
                $errors->add($code, $message, $early->get_error_data($code));
            }
        }
    }

    /**
     * Whether the posted form changes the user's box: true to give, false to take, null for no change.
     * Only a form of the user-edit screen, with this field on it, from a user with full access counts.
     */
    private static function change(int $userId): ?bool
    {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if ($userId < 1 || !wp_verify_nonce($nonce, 'update-user_' . $userId) || empty($_POST[self::SHOWN])) {
            return null;
        }
        if (!AdminAccess::userIsFull(get_current_user_id())) {
            return null;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }
        $wanted = !empty($_POST[self::FIELD]);

        return $wanted === self::holds($user) ? null : $wanted;
    }

    /** Whether the user holds the capability per user (as Kaláka gives it), from WordPress's cached user. */
    public static function holds(\WP_User $user): bool
    {
        return !empty($user->caps[AdminAccess::FULL_ACCESS]);
    }
}
