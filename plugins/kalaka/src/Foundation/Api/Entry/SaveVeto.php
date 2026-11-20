<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Entry;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * Makes a graceful veto loud (foundation.md, "Errors"): the caller keeps its stored state and stores
 * nothing of the refused change, so nothing throws out of a core filter, but code and WP-CLI must learn
 * that the save was not what was asked. One error-log line always; on WP-CLI a warning at the veto and a
 * failing exit when the command ends; with no logged-in user, outside REST, a PHP warning (seen with
 * `WP_DEBUG`).
 *
 * `after_invoke` is not enough for WP-CLI: a command like `wp post update` ends with `exit()` before the
 * hook runs, so the exit is a shutdown function registered on the first veto. That exit calls
 * `WP_CLI::error()`, which exits PHP, so every shutdown function registered after the first veto is
 * skipped; core's `shutdown_action_hook` is registered earlier, so the `shutdown` action still runs.
 * Plain `wp` commands and PHPUnit raise no veto and are unaffected.
 *
 * Any veto fails the whole command: the count is not reset when later saves succeed, so a command that
 * vetoed any save exits 1, even if later saves succeeded.
 *
 * The `wp_trigger_error` warning fires only with `WP_DEBUG` on. The tests run with it off and assert the
 * hook `wp_trigger_error_always_run` instead, so the real `trigger_error` is not exercised there.
 */
final class SaveVeto
{
    /** How many saves this process vetoed, for the command's final error. */
    private static int $vetoed = 0;

    /** Whether the final error is registered. */
    private static bool $registered = false;

    /**
     * Reports one vetoed save.
     *
     * [UNAPPROVED N4] One log line, a WP-CLI warning and exit 1, or a PHP warning with no logged-in
     * user outside REST.
     *
     * @param string $what what was refused, e.g. "saving form 12"
     * @param string $message the refusal's text, as the person would see it
     * @param string $detail the problem's code and subject, for the log line and a developer's warning
     */
    public static function report(string $what, string $message, string $detail = ''): void
    {
        $context = $detail === '' ? $message : $detail . ': ' . $message;
        Boundary::report($what, new InvalidInput($context));
        if (self::isCli()) {
            \WP_CLI::warning($message);
            ++self::$vetoed;
            self::registerExit();
        } elseif (!self::isRest() && get_current_user_id() === 0) {
            wp_trigger_error(__METHOD__, $context, E_USER_WARNING);
        }
    }

    private static function isCli(): bool
    {
        return defined('WP_CLI') && WP_CLI && class_exists('WP_CLI');
    }

    private static function isRest(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }

    /** The command's failing exit: WP-CLI reports the count after the command has printed its own result. */
    private static function registerExit(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function (): void {
            if (self::$vetoed === 0) {
                return;
            }
            \WP_CLI::error(sprintf('%d save(s) were vetoed; see the warnings above.', self::$vetoed));
        });
    }
}
