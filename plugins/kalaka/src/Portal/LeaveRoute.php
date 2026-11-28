<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * `POST kalaka-portal/v1/leave` (registrations-and-identity.md, "Access"): the participant's way out. One
 * press ends the active registration and, for a logged-in account, the login, then answers 303 to a
 * same-site `return_url` (else the home page); it has no body. In this order:
 *
 * 1. A request from another site is refused with the plain 403 page, before anything else happens
 *    (`Sec-Fetch-Site` decides when the browser sends it: `same-origin`, or `none`; else `Origin`, or
 *    failing that `Referer`, must be this site's address; with none of the three it goes through, as old
 *    clients send none). There is no nonce: the registrant may be a guest.
 * 2. A REST request without a nonce has no user by the time the callback runs, so the `logged_in` cookie is
 *    read and validated here and its user set, before the logout.
 * 3. `wp_logout()` for a logged-in account.
 * 4. The active-registration cookie is cleared (also for a visitor who only has it).
 * 5. The 303.
 *
 * Nothing is stored and nothing is audited.
 */
final class LeaveRoute
{
    public const NAMESPACE = 'kalaka-portal/v1';

    public const ROUTE = '/leave';

    /** The same-site refusal's text: the one the sign-up page uses. */
    private const REFUSAL = 'Az űrlapot nem erről a weboldalról küldték el. Nyisd meg újra itt, ezen a weboldalon, '
        . 'és próbáld meg még egyszer.';

    public function __construct(private readonly Response $response = new HttpResponse())
    {
    }

    /** On `rest_api_init`. */
    public function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'leave'],
            'permission_callback' => '__return_true',
            'args' => ['return_url' => ['type' => 'string', 'required' => false]],
        ]);
    }

    /** @internal the route's callback */
    public function leave(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!self::sameSite($request)) {
            self::refuse();
        }

        $user = self::cookieUser();
        if ($user > 0) {
            wp_set_current_user($user);
            wp_logout();
        }
        $this->response->setCookie(LinkHandler::COOKIE, '', -1);
        $this->response->noCache();
        $this->response->redirect(self::target($request->get_param('return_url')), 303);

        // Only a test's response gets here: the real one ends the request.
        return new \WP_REST_Response(null, 303);
    }

    /** The user the `logged_in` cookie names, 0 for none or an invalid one: REST dropped the user by now. */
    private static function cookieUser(): int
    {
        $name = defined('LOGGED_IN_COOKIE') ? (string) LOGGED_IN_COOKIE : '';
        $cookie = $name !== '' && isset($_COOKIE[$name]) && is_string($_COOKIE[$name])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as a whole just below
            ? wp_unslash($_COOKIE[$name]) : '';
        if ($cookie === '') {
            return 0;
        }
        $id = wp_validate_auth_cookie($cookie, 'logged_in');

        return $id === false ? 0 : (int) $id;
    }

    /** A same-site `return_url`, else the home page. */
    private static function target(mixed $returnUrl): string
    {
        $home = home_url('/');
        if (!is_string($returnUrl) || $returnUrl === '') {
            return $home;
        }

        return wp_validate_redirect(esc_url_raw($returnUrl), $home);
    }

    /** Whether the request's headers allow it: see the class comment. */
    private static function sameSite(\WP_REST_Request $request): bool
    {
        $fetch = $request->get_header('sec_fetch_site');
        if ($fetch !== null) {
            return in_array(strtolower($fetch), ['same-origin', 'none'], true);
        }
        $from = $request->get_header('origin') ?? $request->get_header('referer');
        if ($from === null || $from === '') {
            return true;
        }
        $origin = self::origin($from);

        return $origin !== null && in_array($origin, [self::origin(home_url()), self::origin(site_url())], true);
    }

    /** `scheme://host:port` of an address, lower-case with the default port filled in; null when it has none. */
    private static function origin(string $url): ?string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /**
     * The 403 page, never JSON: a request that reaches here may be a REST one, and WordPress would answer
     * a REST or JSON request with its JSON handler, so every handler but the HTML one is set to it.
     */
    private static function refuse(): never
    {
        $html = static fn (): mixed => apply_filters('wp_die_handler', '_default_wp_die_handler');
        foreach (['ajax', 'json', 'jsonp', 'xml'] as $kind) {
            add_filter('wp_die_' . $kind . '_handler', $html, 99);
        }
        wp_die(esc_html(self::REFUSAL), '', ['response' => 403, 'back_link' => false]);
    }
}
