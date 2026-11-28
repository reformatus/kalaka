<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Registrations\Account\SignUpPage;

/**
 * Login CSRF: `wp-login.php` refuses a POST from another site (any action), with the auto-login of a sign-up
 * the reason. Same-origin and no-header requests go through, and so does a provider's own form_post callback.
 */
final class LoginCsrfTest extends AccountsTestCase
{
    private const EMAIL = 'teszt.tamas@example.invalid';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's name
    public function tear_down(): void
    {
        unset(
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tear_down();
    }

    /**
     * @param array<string, string> $headers `$_SERVER` keys
     * @param array<string, string> $request
     * @param array<string, string> $post
     */
    private function post(string $action, array $headers, array $request = [], array $post = []): void
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- a test
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach ($headers as $key => $value) {
            $_SERVER[$key] = $value;
        }
        $_POST = ['user_email' => self::EMAIL, 'log' => 'teszt', 'redirect_to' => home_url('/')] + $post;
        $_REQUEST = $_POST + $request;
        // phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        (new SignUpPage())->refuseCrossSite();
        do_action('login_form_' . $action);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function crossSite(): iterable
    {
        foreach (['register', 'login'] as $action) {
            yield $action . ', Sec-Fetch-Site cross-site' => [$action, ['HTTP_SEC_FETCH_SITE' => 'cross-site']];
            yield $action . ', same-site is not same-origin' => [$action, ['HTTP_SEC_FETCH_SITE' => 'same-site']];
            yield $action . ', foreign Origin' => [$action, ['HTTP_ORIGIN' => 'https://evil.example.invalid']];
            yield $action . ', Origin null' => [$action, ['HTTP_ORIGIN' => 'null']];
            yield $action . ', foreign Referer' => [$action, ['HTTP_REFERER' => 'https://evil.example.invalid/x']];
            yield $action . ', a foreign Origin beats a same-site Referer' => [
                $action,
                ['HTTP_ORIGIN' => 'https://evil.example.invalid', 'HTTP_REFERER' => home_url('/')],
            ];
        }
    }

    /**
     * @dataProvider crossSite
     * @param array<string, string> $headers
     */
    public function testACrossSitePostIsRefusedBeforeAnythingHappens(string $action, array $headers): void
    {
        try {
            $this->post($action, $headers);
            self::fail('The request was not refused.');
        } catch (\WPDieException) {
            // Refused.
        }

        self::assertFalse(SignUpPage::flagged());
        self::assertFalse(get_user_by('email', self::EMAIL));
        self::assertSame(0, get_current_user_id());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function allowed(): iterable
    {
        yield 'same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'none, a direct navigation' => [['HTTP_SEC_FETCH_SITE' => 'none']];
        yield 'Origin of the site' => [['HTTP_ORIGIN' => home_url()]];
        yield 'Referer on the site' => [['HTTP_REFERER' => home_url('/wp-login.php?action=register')]];
        yield 'no headers' => [[]];
    }

    /**
     * @dataProvider allowed
     * @param array<string, string> $headers
     */
    public function testASameOriginPostAndOneWithNoHeadersGoThrough(array $headers): void
    {
        $this->post('register', $headers);

        self::assertTrue(SignUpPage::flagged());
    }

    public function testASameOriginSignUpCreatesTheAccountAndLogsItIn(): void
    {
        $this->post('register', ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $id = register_new_user('teszt-tamas', self::EMAIL);

        self::assertIsInt($id);
        self::assertSame($id, get_current_user_id());
    }

    private const APPLE = 'https://appleid.apple.com';

    /** @return iterable<string, array{array<string, string>, array<string, string>}> */
    public static function refusedSocial(): iterable
    {
        $callback = ['code' => 'teszt-kod', 'state' => 'teszt-allapot'];
        $apple = ['loginSocial' => 'apple'] + $callback;
        yield 'forged, loginSocial=google, foreign Origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example.invalid'],
            ['loginSocial' => 'google'] + $callback,
        ];
        yield 'forged, loginSocial=google, Apple origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => self::APPLE],
            ['loginSocial' => 'google'] + $callback,
        ];
        yield 'forged, any value' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example.invalid'],
            ['loginSocial' => 'x'],
        ];
        yield 'apple, foreign Origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example.invalid'],
            $apple,
        ];
        yield 'apple, foreign Referer, no Origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://evil.example.invalid/'],
            $apple,
        ];
        yield 'apple, no Origin and no Referer' => [['HTTP_SEC_FETCH_SITE' => 'cross-site'], $apple];
        yield 'apple, Apple lookalike origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://appleid.apple.com.evil.example.invalid'],
            $apple,
        ];
        yield 'apple, without code' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => self::APPLE],
            ['loginSocial' => 'apple', 'state' => 'teszt-allapot'],
        ];
        yield 'apple, without state' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => self::APPLE],
            ['loginSocial' => 'apple', 'code' => 'teszt-kod'],
        ];
    }

    /**
     * @dataProvider refusedSocial
     * @param array<string, string> $headers
     * @param array<string, string> $request
     */
    public function testAForgedOrIncompleteSocialLoginPostIsRefusedWithTheText(array $headers, array $request): void
    {
        $status = null;
        add_filter('wp_die_handler', static function () use (&$status): callable {
            return static function (string|\WP_Error $message, string|int $title, array $args) use (&$status): void {
                $status = $args['response'] ?? null;
                $text = is_string($message) ? $message : $message->get_error_message();
                // phpcs:ignore WordPress.Security.EscapeOutput -- a test
                throw new \WPDieException($text);
            };
        }, 99);
        try {
            $this->post('login', $headers, $request, $request);
            self::fail('The request was not refused.');
        } catch (\WPDieException $e) {
            self::assertSame(403, $status);
            self::assertStringContainsString(
                'Az űrlapot nem erről a weboldalról küldték el. Nyisd meg újra itt, ezen a weboldalon, '
                . 'és próbáld meg még egyszer.',
                $e->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function appleCallbacks(): iterable
    {
        yield 'Origin' => [['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => self::APPLE]];
        yield 'Referer, no Origin' => [
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => self::APPLE . '/auth/x'],
        ];
    }

    /**
     * @dataProvider appleCallbacks
     * @param array<string, string> $headers
     */
    public function testAnAppleCallbackPostWithCodeAndStateIsLetThrough(array $headers): void
    {
        $request = ['loginSocial' => 'apple', 'code' => 'teszt-kod', 'state' => 'teszt-allapot'];

        $this->post('login', $headers, $request, $request);

        self::assertFalse(SignUpPage::flagged());
    }

    public function testTheCheckRunsOnLoginInitBeforeEveryLoginAction(): void
    {
        $priority = false;
        foreach ($GLOBALS['wp_filter']['login_init']->callbacks as $at => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][1] === 'refuseCrossSite') {
                    $priority = $at;
                }
            }
        }

        self::assertSame(1, $priority);
    }

    public function testAGetIsNeverRefused(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        (new SignUpPage())->refuseCrossSite();

        $this->addToAssertionCount(1);
    }
}
