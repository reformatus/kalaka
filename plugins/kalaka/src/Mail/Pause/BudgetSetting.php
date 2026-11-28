<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The sender's hourly budget as a WordPress setting that WordPress never stores [UNAPPROVED N1188]: a screen
 * saves it through `options.php` and `settings_fields( 'kalaka_mail' )` like any setting, while the one value
 * stays in the sender's state row and changes only through `SetMailBudget` (mail.md, "Sending"). It is a
 * virtual setting over that row:
 *
 * - `get_option()` answers the row's current budget (`pre_option_…`), for full access only, like the sender's
 *   read;
 * - the sanitize step runs `SetMailBudget` as the saving user, so the use case's own refusal (`MailBudgetInvalid`
 *   or any other) becomes a settings error with its own text, and what it returns is the stored budget;
 * - `pre_update_option_…` hands back the old value, so `update_option()` finds nothing to write and no
 *   `kalaka_mail_hourly_budget` row reaches `wp_options` through it (the filters exist on `admin_init`, so a
 *   request outside the admin side, WP-CLI's `wp option`, is not covered);
 * - the page's capability is full access, the gate the use case and the `/mail` read hold.
 */
final class BudgetSetting
{
    /** The settings group: `settings_fields()` and `settings_errors()` take it. */
    public const GROUP = 'kalaka_mail';

    /** The option name, and the name of the form's input. */
    public const OPTION = 'kalaka_mail_hourly_budget';

    /** `admin_init`: the setting exists on the admin side only. */
    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type' => 'integer',
            'default' => MailState::DEFAULT_HOURLY_BUDGET,
            'sanitize_callback' => [self::class, 'sanitize'],
            'show_in_rest' => false,
        ]);
        add_filter('pre_option_' . self::OPTION, [self::class, 'read']);
        add_filter('pre_update_option_' . self::OPTION, [self::class, 'keep'], 10, 2);
        add_filter('option_page_capability_' . self::GROUP, [self::class, 'capability']);
    }

    /**
     * `pre_option_…`: the state row's budget; anything else (not full access, the row unreadable) leaves
     * `get_option()` to its own answer, the registered default.
     */
    public static function read(mixed $pre): mixed
    {
        if (!current_user_can(AdminAccess::FULL_ACCESS)) {
            return $pre;
        }

        return self::stored() ?? $pre;
    }

    /**
     * `sanitize_option_…`, which `update_option()` runs on the posted value: saves it through `SetMailBudget`
     * and returns the budget as it is stored afterwards (the old one after a refusal).
     */
    public static function sanitize(mixed $value): int
    {
        $given = is_scalar($value) ? $value : null;
        try {
            Runner::current()->run(
                new SetMailBudget(),
                ['hourly_budget' => $given],
                new UserActor(get_current_user_id())
            );
        } catch (\Throwable $e) {
            $shown = Boundary::shown('saving the mail budget', $e);
            // One message per line, as the error's second line is the identifier of a failure.
            $lines = array_map(esc_html(...), explode("\n", $shown->displayMessage()));
            add_settings_error(self::GROUP, $shown->errorCode(), implode('<br>', $lines), 'error');
        }

        return self::stored() ?? MailState::DEFAULT_HOURLY_BUDGET;
    }

    /** `pre_update_option_…`: the old value, so the option is never written, not even when it is missing. */
    public static function keep(mixed $value, mixed $old): mixed
    {
        return $old;
    }

    /** `option_page_capability_kalaka_mail`: who `options.php` lets save this group. */
    public static function capability(): string
    {
        return AdminAccess::FULL_ACCESS;
    }

    /** The budget in the state row, read past every cache; null when the read fails (logged). */
    private static function stored(): ?int
    {
        try {
            return (new MailStateQuery())->current()->hourlyBudget;
        } catch (\Throwable $e) {
            Boundary::report('reading the mail budget setting', $e);

            return null;
        }
    }
}
