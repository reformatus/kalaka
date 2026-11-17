<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The way from a registration form that needs an account to WordPress's own login and registration pages
 * and back (owner rulings 2026-11-14 and 2026-11-17; registrations-and-identity.md, "Accounts on the
 * registration form"):
 *
 * - `url()`: WordPress's login page first (OP5), `wp_login_url()` with `redirect_to` (the form's page) and
 *   the marker `kalaka_form` (the form's ID), which the login and registration pages read to know a Kaláka
 *   form sent the visitor; `registerUrl()` is the registration page with the same two;
 * - `skipUrl()`: the form's page with `kalaka_skip` (the form's ID), the skip link of `optional` mode. The
 *   form page then shows the form to someone not logged in instead of sending them to sign up. It only
 *   avoids the redirect: it fills nothing in;
 * - `page()`: the form's page, resolved on the server from the form's ID alone (OP1, OP5). Every way back
 *   Kaláka builds from a marker is this, never an address taken from the request.
 */
final class SignUp
{
    /** The marker on the registration page: the ID of the form that sent the visitor. */
    public const MARKER = 'kalaka_form';

    /** The skip marker on the form's page: the ID of the form shown without an account. */
    public const SKIP = 'kalaka_skip';

    /** WordPress's login page for the form, returning to `$return` (the form's page). */
    public static function url(int $formId, string $return): string
    {
        return add_query_arg(self::MARKER, $formId, wp_login_url($return));
    }

    /** WordPress's registration page for the form, returning to `$return` (the form's page). */
    public static function registerUrl(int $formId, string $return): string
    {
        return add_query_arg(
            [self::MARKER => $formId, 'redirect_to' => rawurlencode($return)],
            wp_registration_url()
        );
    }

    /** The form's page with the skip marker for the form. */
    public static function skipUrl(int $formId, string $return): string
    {
        return add_query_arg(self::SKIP, $formId, $return);
    }

    /**
     * Whether the request skips the sign-up for the form: its query carries the form's skip marker.
     *
     * @param array<string, mixed> $query the request's query parameters
     */
    public static function skipped(int $formId, array $query): bool
    {
        $skip = $query[self::SKIP] ?? null;

        return is_string($skip) && $skip === (string) $formId;
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
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            's' => 'kalaka',
            'search_columns' => ['post_content'],
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => 100,
            'no_found_rows' => true,
        ]);
        $pages = [];
        foreach ($posts as $post) {
            $placed = in_array($formId, FormCaching::formIds($post->post_content), true);
            if ($placed && is_post_publicly_viewable($post)) {
                $link = get_permalink($post);
                if ($link !== '') {
                    $pages[] = $link;
                }
            }
        }
        if ($pages === []) {
            return null;
        }

        return in_array($requested, $pages, true) ? $requested : $pages[0];
    }
}
