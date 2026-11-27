<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Portal\LeaveRoute;
use Kalaka\Portal\LinkHandler;
use Kalaka\Portal\ParticipantBlocks;

/**
 * The leave block and its route: one press clears the active registration and logs an account out, then 303s
 * to a same-site address; another site's post gets the plain 403 page and changes nothing.
 */
final class LeaveTest extends PortalTestCase
{
    private RecordingResponse $response;

    private int $user = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->response = new RecordingResponse();
        // The sandbox has sent output already: no real cookie header.
        add_filter('send_auth_cookies', '__return_false');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        unset($_COOKIE[LOGGED_IN_COOKIE], $_SERVER['REQUEST_URI']);
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** @param array<string, string> $headers WordPress's header names, lower case with underscores */
    private function post(?string $returnUrl = null, array $headers = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/' . LeaveRoute::NAMESPACE . LeaveRoute::ROUTE);
        if ($returnUrl !== null) {
            $request->set_param('return_url', $returnUrl);
        }
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return (new LeaveRoute($this->response))->leave($request);
    }

    /** A logged-in account: its cookie is in the request, but the REST layer has dropped the user (no nonce). */
    private function loggedInWithoutNonce(): int
    {
        $this->user = self::factory()->user->create();
        $expiry = time() + HOUR_IN_SECONDS;
        $token = \WP_Session_Tokens::get_instance($this->user)->create($expiry);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($this->user, $expiry, 'logged_in', $token);
        wp_set_current_user(0);

        return $this->user;
    }

    public function testASameSitePostLogsTheAccountOutClearsTheRegistrationAnd303sToTheReturnAddress(): void
    {
        $this->loggedInWithoutNonce();

        $this->post(home_url('/allapot/'), ['sec_fetch_site' => 'same-origin']);

        self::assertSame([], \WP_Session_Tokens::get_instance($this->user)->get_all());
        self::assertSame(0, get_current_user_id());
        self::assertSame(['', -1], $this->response->cookies[LinkHandler::COOKIE]);
        self::assertSame(home_url('/allapot/'), $this->response->redirect);
        self::assertSame(303, $this->response->redirectStatus);
    }

    public function testAnAccountWithoutANonceIsLoggedOutBecauseTheCookieIsReadBeforeTheLogout(): void
    {
        $this->loggedInWithoutNonce();
        self::assertSame(0, get_current_user_id());
        self::assertNotSame([], \WP_Session_Tokens::get_instance($this->user)->get_all());

        $this->post();

        self::assertSame([], \WP_Session_Tokens::get_instance($this->user)->get_all());
    }

    public function testAForgedLoggedInCookieLogsNobodyOut(): void
    {
        $user = self::factory()->user->create();
        \WP_Session_Tokens::get_instance($user)->create(time() + HOUR_IN_SECONDS);
        $_COOKIE[LOGGED_IN_COOKIE] = $user . '|' . (time() + HOUR_IN_SECONDS) . '|token|forged';

        $this->post();

        self::assertCount(1, \WP_Session_Tokens::get_instance($user)->get_all());
        self::assertSame(303, $this->response->redirectStatus);
    }

    public function testAReturnAddressOnAnotherSiteGoesHome(): void
    {
        $this->post('https://evil.example.invalid/allapot/');

        self::assertSame(home_url('/'), $this->response->redirect);
        self::assertSame(303, $this->response->redirectStatus);
    }

    public function testNoReturnAddressGoesHome(): void
    {
        $this->post();

        self::assertSame(home_url('/'), $this->response->redirect);
    }

    public function testAVisitorWithNeitherAnAccountNorACookieStillGets303AndClearsOnlyTheRegistrationCookie(): void
    {
        $this->post(home_url('/allapot/'));

        self::assertSame(303, $this->response->redirectStatus);
        self::assertSame([LinkHandler::COOKIE], array_keys($this->response->cookies));
        self::assertSame(0, get_current_user_id());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function crossSite(): iterable
    {
        yield 'Sec-Fetch-Site cross-site' => [['sec_fetch_site' => 'cross-site']];
        yield 'same-site is not same-origin' => [['sec_fetch_site' => 'same-site']];
        yield 'foreign Origin' => [['origin' => 'https://evil.example.invalid']];
        yield 'foreign Referer' => [['referer' => 'https://evil.example.invalid/x']];
    }

    /**
     * @dataProvider crossSite
     * @param array<string, string> $headers
     */
    public function testACrossSitePostGetsThe403PageAndLogsNobodyOut(array $headers): void
    {
        $this->loggedInWithoutNonce();
        $handled = null;
        // The JSON handler would be the REST default: the route must make the plain page win.
        add_filter('wp_die_json_handler', static fn (): string => '_json_wp_die_handler', 1);
        add_filter('wp_die_handler', static function () use (&$handled): \Closure {
            return static function (string $message, string $title, array $args) use (&$handled): never {
                $handled = [$message, $args['response'] ?? null];
                throw new \WPDieException(esc_html($message));
            };
        });
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        try {
            $this->post(home_url('/allapot/'), $headers);
            self::fail('The request was not refused.');
        } catch (\WPDieException) {
            // Refused.
        } finally {
            unset($_SERVER['HTTP_ACCEPT']);
        }

        self::assertNotNull($handled);
        self::assertSame(403, $handled[1]);
        self::assertStringStartsWith('Az űrlapot nem erről a weboldalról', $handled[0]);
        self::assertNotSame([], \WP_Session_Tokens::get_instance($this->user)->get_all());
        self::assertNull($this->response->redirect);
        self::assertSame([], $this->response->cookies);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function sameSite(): iterable
    {
        yield 'same-origin' => [['sec_fetch_site' => 'same-origin']];
        yield 'none, a direct navigation' => [['sec_fetch_site' => 'none']];
        yield 'Origin of the site' => [['origin' => home_url()]];
        yield 'Referer on the site' => [['referer' => home_url('/allapot/')]];
        yield 'no headers' => [[]];
    }

    /**
     * @dataProvider sameSite
     * @param array<string, string> $headers
     */
    public function testASameSiteOrHeaderlessPostGoesThrough(array $headers): void
    {
        $this->post(home_url('/allapot/'), $headers);

        self::assertSame(home_url('/allapot/'), $this->response->redirect);
    }

    public function testTheRouteIsRegisteredAsAPostRouteWithoutANonceOrALogin(): void
    {
        $routes = rest_get_server()->get_routes(LeaveRoute::NAMESPACE);

        self::assertArrayHasKey('/' . LeaveRoute::NAMESPACE . LeaveRoute::ROUTE, $routes);
        $endpoint = $routes['/' . LeaveRoute::NAMESPACE . LeaveRoute::ROUTE][0];
        self::assertArrayHasKey('POST', $endpoint['methods']);
    }

    public function testTheBlockRendersNothingWithoutARegistrationOrAnAccount(): void
    {
        self::assertSame('', ParticipantBlocks::leave());
        self::assertSame('', do_shortcode('[kalaka_leave]'));
        self::assertSame('', trim(do_blocks('<!-- wp:kalaka/leave /-->')));
    }

    public function testTheBlockRendersTheFormWithTheActiveRegistrationAndWithAnAccount(): void
    {
        $_SERVER['REQUEST_URI'] = '/allapot/?x=1';
        $this->activate($this->stored('12A3'));

        $html = ParticipantBlocks::leave();

        self::assertSame($html, do_shortcode('[kalaka_leave]'));
        self::assertSame(trim($html), trim(str_replace("\n", '', do_blocks('<!-- wp:kalaka/leave /-->'))));
        self::assertStringContainsString('<form class="kalaka-leave" method="post" action="', $html);
        self::assertStringContainsString(rest_url('kalaka-portal/v1/leave'), $html);
        self::assertSame(1, substr_count($html, '<button'));
        self::assertStringContainsString(
            'value="' . esc_attr(self::origin() . '/allapot/?x=1') . '"',
            $html
        );
        self::assertTrue(defined('DONOTCACHEPAGE'));

        $this->activate(null);
        self::assertSame('', ParticipantBlocks::leave());
        wp_set_current_user(self::factory()->user->create());
        self::assertStringContainsString('<form class="kalaka-leave"', ParticipantBlocks::leave());
    }

    public function testAPageHoldingTheBlockOrTheShortcodeIsFoundAsHoldingAParticipantBlock(): void
    {
        self::assertTrue(ParticipantBlocks::placedIn('<!-- wp:kalaka/leave /-->'));
        self::assertTrue(ParticipantBlocks::placedIn('[et_pb_text][kalaka_leave][/et_pb_text]'));
    }

    public function testTheBlockIsRegistered(): void
    {
        self::assertNotNull(\WP_Block_Type_Registry::get_instance()->get_registered(ParticipantBlocks::LEAVE_BLOCK));
    }

    private static function origin(): string
    {
        $parts = wp_parse_url(home_url('/'));

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
