<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Engine\Rest\FormRoutes;

/**
 * S15 (UX review 2): a form's request whose session ended (the password was set on another device, which
 * logs out every session) is refused by WordPress's cookie check before any route runs. For the form's
 * routes that refusal becomes `kalaka_session_ended`; other routes and other errors are left alone. The
 * steps are `WP_REST_Server::serve_request()`'s: the authentication check, the error as a response, then
 * `rest_post_dispatch` with the request.
 */
final class SessionEndedTest extends \WP_UnitTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        unset($GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE']);
        wp_set_current_user(0);
        parent::tear_down();
    }

    /** What the server answers a cookie-authenticated request whose nonce was made in an ended session. */
    private function serve(string $route): \WP_REST_Response
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce('wp_rest');
        // Logged out meanwhile: the cookie no longer names the user.
        wp_set_current_user(0);
        $GLOBALS['wp_rest_auth_cookie'] = true;

        $result = apply_filters('rest_authentication_errors', null);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('rest_cookie_invalid_nonce', $result->get_error_code());
        $response = apply_filters(
            'rest_post_dispatch',
            rest_convert_error_to_response($result),
            rest_get_server(),
            new \WP_REST_Request('POST', $route)
        );
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    public function testTheFormsRoutesAnswerThatTheSessionEnded(): void
    {
        foreach (['/kalaka-portal/v1/forms/12/submit', '/kalaka-portal/v1/forms/12/availability'] as $route) {
            $response = $this->serve($route);

            self::assertSame(401, $response->get_status(), $route);
            self::assertSame(FormRoutes::SESSION_ENDED, $response->as_error()?->get_error_code());
            self::assertSame(FormCaching::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null);
        }
    }

    public function testAnotherRouteKeepsWordPresssRefusal(): void
    {
        $response = $this->serve('/wp/v2/posts');

        self::assertSame(403, $response->get_status());
        self::assertSame('rest_cookie_invalid_nonce', $response->as_error()?->get_error_code());
    }

    public function testAnotherErrorOfTheFormsRoutesPassesUnchanged(): void
    {
        $other = rest_convert_error_to_response(new \WP_Error('kalaka_not_found', 'x', ['status' => 404]));

        $response = apply_filters(
            'rest_post_dispatch',
            $other,
            rest_get_server(),
            new \WP_REST_Request('POST', '/kalaka-portal/v1/forms/12/submit')
        );

        self::assertSame($other, $response);
    }
}
