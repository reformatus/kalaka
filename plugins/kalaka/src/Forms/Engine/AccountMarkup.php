<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\ElementRole;

/**
 * The account block's markup (`Api\SignUp::BLOCK`, owner ruling 2026-11-18), for a form whose host gives an
 * account step (`FormHost::accountStep()`); the form's page renders it where the block sits, and the
 * re-fetch re-renders it after a login in the block's window (`Rest\FormRoutes`).
 *
 * - Not logged in: what an account gives (in `mandatory` mode first that the form needs one; in `optional`
 *   mode what going without one costs, OP3, and the 16+ notice, T7), then ONE button that opens WordPress's
 *   login page in a window of its own (the page's script, `actions.logIn`). Sign-up and social login are
 *   reached from the login page. An error element the script fills when the block refuses „Tovább” or
 *   „Küldés” in `mandatory` mode.
 * - Logged in: who is logged in and the logout link back to the form's page (OP4). The name is the account's
 *   display name, unless it is empty or only the login name (WordPress's default, and a Kaláka sign-up's):
 *   then the e-mail address (N26).
 */
final class AccountMarkup
{
    /** The key of the block's error in a re-fetch's `errors`: never a field key (those have no `/`). */
    public const ERROR_KEY = 'kalaka/account';

    // T9 (approved by the owner).
    public const GIVES = 'Fiókkal később bejelentkezve is eléred a jelentkezésedet.';

    // Approved 2026-11-17 for the fallback; in the block since 2026-11-18.
    public const REQUIRED = 'A jelentkezéshez fiók szükséges.';
    public const BUTTON = 'Fiókot készítek vagy bejelentkezem';

    // Approved 2026-11-17 on the sign-up pages; moved into the block 2026-11-18.
    public const WITHOUT = 'Fiók nélkül később csak az e-mailben kapott linkkel éred el a jelentkezésedet.';
    public const AGE = 'Fiókot 16 éves kortól készíthetsz. Ha még nem vagy 16 éves, folytasd fiók nélkül.';

    // The block's error in `mandatory` mode.
    public const ERROR = 'Előbb készíts fiókot, vagy jelentkezz be.';

    /** The block for the current visitor; `$page` is the form's page, where the logout returns. */
    public static function html(AccountStep $step, string $page): string
    {
        return is_user_logged_in() ? self::loggedIn($page) : self::loggedOut($step);
    }

    /** The account the block names: the display name, unless empty or the login; else the e-mail. */
    public static function name(\WP_User $user): string
    {
        $name = trim($user->display_name);

        return $name !== '' && $name !== $user->user_login ? $name : $user->user_email;
    }

    private static function loggedIn(string $page): string
    {
        // Approved 2026-11-17 as the footer; in the block since the owner's ruling 2026-11-18.
        return sprintf(
            '<div class="kalaka-account" data-kalaka-account="in"><p>%s <a href="%s">%s</a></p></div>',
            esc_html('Bejelentkezve: ' . self::name(wp_get_current_user()) . '. Nem te vagy?'),
            esc_url(wp_logout_url($page)),
            esc_html('Kijelentkezés')
        );
    }

    private static function loggedOut(AccountStep $step): string
    {
        $lines = $step === AccountStep::Required
            ? [self::REQUIRED . ' ' . self::GIVES]
            : [self::GIVES, self::WITHOUT, self::AGE];
        $html = '<div class="kalaka-account" data-kalaka-account="out">';
        foreach ($lines as $line) {
            $html .= '<p>' . esc_html($line) . '</p>';
        }
        $html .= sprintf(
            '<p><button type="button"%s data-kalaka-account-login data-wp-on--click="actions.logIn">%s</button></p>',
            FieldMarkup::classAttribute(ElementRole::SubmitButton),
            esc_html(self::BUTTON)
        );

        return $html . '<p id="kalaka-account-error"'
            . FieldMarkup::classAttribute(ElementRole::ErrorMessage, 'kalaka-field__error') . ' hidden></p></div>';
    }
}
