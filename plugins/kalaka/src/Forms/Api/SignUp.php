<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The way from a registration form that needs or offers an account to WordPress's own login and
 * registration pages and back (owner rulings 2026-11-14, 2026-11-17 and 2026-11-18; registrations-and-identity.md,
 * "Accounts on the registration form"). The form's account block (`BLOCK`) opens the login page in a window of
 * its own:
 *
 * - `url()`: WordPress's login page first (OP5), `wp_login_url()` with the marker `kalaka_form` (the form's
 *   ID), which the login and registration pages read to know a Kaláka form sent the visitor, and the return
 *   page as `redirect_to`; `registerUrl()` is the registration page with the same two;
 * - `returnUrl()`: the return page (`wp-login.php?action=kalaka_account`), where a login or sign-up from the
 *   form's window lands. It tells the form's page it opened from (`postMessage`, same origin only) and closes
 *   itself; with no such page left, it goes to the form's page;
 * - `page()`: the form's page, resolved on the server from the form's ID alone (OP1, OP5). Every way back
 *   Kaláka builds from a marker is this, never an address taken from the request.
 */
final class SignUp
{
    /** How many posts `page()` reads at a time. */
    private const BATCH = 100;

    /** The marker on the registration page: the ID of the form that sent the visitor. */
    public const MARKER = 'kalaka_form';

    /** The account block (owner ruling 2026-11-18): on every registration form of an event with accounts. */
    public const BLOCK = 'kalaka/account';

    /**
     * The condition subject „Bejelentkezve” (owner ruling 2026-11-18): `yes` while someone is
     * logged in on a form with an account step, `no` there when not, none on a form without one (owner ruling
     * 2026-11-18: true, false and null are separate). A chain reading it ships every branch, like an
     * answer-driven one (`FormDefinition::withheldChains()`), because the in-place login changes it.
     */
    public const LOGGED_IN = 'account.logged_in';

    /** The subject's name, for a condition editor that names subjects (none names any yet). */
    public const LOGGED_IN_LABEL = 'Bejelentkezve';

    /** The return page's `action` on `wp-login.php` (`returnUrl()`). */
    public const RETURN_ACTION = 'kalaka_account';

    /** WordPress's login page for the form, returning through the return page to `$page` (the form's page). */
    public static function url(int $formId, string $page): string
    {
        return add_query_arg(self::MARKER, $formId, wp_login_url(self::returnUrl($formId, $page)));
    }

    /** WordPress's registration page for the form, returning through the return page to `$page`. */
    public static function registerUrl(int $formId, string $page): string
    {
        return add_query_arg(
            [self::MARKER => $formId, 'redirect_to' => rawurlencode(self::returnUrl($formId, $page))],
            wp_registration_url()
        );
    }

    /**
     * The return page for the form: `wp-login.php?action=kalaka_account` with the marker and the form's page
     * as `redirect_to`, which only picks among the pages placing the form (`page()`). It is core's own
     * `wp-login.php` address, as `wp_login_url()` starts from, without that function's filter: Kaláka's
     * `login_url` filter builds this address itself.
     */
    public static function returnUrl(int $formId, string $page): string
    {
        return add_query_arg(
            ['action' => self::RETURN_ACTION, self::MARKER => $formId, 'redirect_to' => rawurlencode($page)],
            site_url('wp-login.php', 'login')
        );
    }

    /**
     * The page a request's `redirect_to` asks for: the address itself, or, for a return page's address
     * (`returnUrl()`), the page it carries. It only ever picks among the form's pages (`page()`).
     */
    public static function requested(string $redirectTo): string
    {
        $query = (string) wp_parse_url($redirectTo, PHP_URL_QUERY);
        parse_str($query, $args);
        $inner = $args['redirect_to'] ?? null;

        return ($args['action'] ?? null) === self::RETURN_ACTION && is_string($inner) ? $inner : $redirectTo;
    }

    /**
     * The form ID a marker value names, or null for anything that is not a positive integer.
     */
    public static function formIdOf(mixed $marker): ?int
    {
        return is_string($marker) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $marker) === 1 ? (int) $marker : null;
    }

    /**
     * The page of the form, found on the server: the published, publicly viewable posts whose content
     * places the form (the block `kalaka/form` or the `[kalaka_form]` shortcode), as their permalink. A
     * `$requested` address only picks among them (the one equal to it); otherwise the lowest post ID wins.
     * Null when no such post places the form (a theme's or a builder's template): then the way back is
     * WordPress's own, as without a form.
     */
    public static function page(int $formId, string $requested = ''): ?string
    {
        $pages = [];
        // Every post whose content mentions Kaláka, in batches until one comes back short: none is missed.
        for ($batch = 1;; $batch++) {
            $posts = get_posts([
                'post_type' => 'any',
                'post_status' => 'publish',
                's' => 'kalaka',
                'search_columns' => ['post_content'],
                'orderby' => 'ID',
                'order' => 'ASC',
                'posts_per_page' => self::BATCH,
                'paged' => $batch,
                'no_found_rows' => true,
            ]);
            foreach ($posts as $post) {
                $placed = in_array($formId, FormCaching::formIds($post->post_content), true);
                if ($placed && is_post_publicly_viewable($post)) {
                    $link = get_permalink($post);
                    if ($link !== '') {
                        $pages[] = $link;
                    }
                }
            }
            if (count($posts) < self::BATCH) {
                break;
            }
        }
        if ($pages === []) {
            return null;
        }

        $requested = self::requested($requested);

        return in_array($requested, $pages, true) ? $requested : $pages[0];
    }
}
