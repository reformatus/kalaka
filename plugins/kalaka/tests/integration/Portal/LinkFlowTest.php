<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\LinkHandler;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SecretLinks;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\SecretLink;

/**
 * The secret link and the active-registration cookie (SB-5, SB-7, SB-10, SB-12): the redirect without the
 * token, the signed session cookie, its 24-hour cap, revocation by a new secret, the one-shot bad-link
 * flag, logged in without a cookie, and no-cache on every request that carries either cookie.
 */
final class LinkFlowTest extends PortalTestCase
{
    private RecordingResponse $response;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->response = new RecordingResponse();
    }

    /**
     * One request through the handler.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $cookies
     */
    private function request(
        array $query = [],
        array $cookies = [],
        string $now = self::NOW,
        string $method = 'GET',
        string $url = '/allapot/',
    ): RecordingResponse {
        $this->response = new RecordingResponse();
        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }
        (new LinkHandler(new SecretLinks(), $this->response))
            ->handle($query, $cookies, $method, $url, $this->utc($now));

        return $this->response;
    }

    private function secretOf(RegistrationKey $key): string
    {
        $row = $this->rowOf('kalaka_registrations', $key->eventId, $key->code);
        self::assertIsArray($row);

        return (string) $row['secret'];
    }

    private function linkOf(RegistrationKey $key): string
    {
        return $key->eventId . '.' . $key->code . '.' . $this->secretOf($key);
    }

    /** Follows the link and returns the cookie it set. */
    private function follow(RegistrationKey $key, string $now = self::NOW): string
    {
        $response = $this->request([SecretLinks::PARAMETER => $this->linkOf($key)], [], $now);
        [$token, $expires] = $response->cookies[LinkHandler::COOKIE];
        self::assertSame(0, $expires, 'a session cookie');

        return $token;
    }

    public function testAMatchingLinkSetsTheSignedSessionCookieAndRedirectsWithoutTheToken(): void
    {
        $key = $this->stored('12A3');

        $response = $this->request([SecretLinks::PARAMETER => $this->linkOf($key), 'x' => '1']);

        self::assertSame('/allapot/?x=1', $response->redirect);
        self::assertSame(1, $response->noCache);
        [$token] = $response->cookies[LinkHandler::COOKIE];
        self::assertMatchesRegularExpression('/\Av1\.5\.12A3\.[0-9]+\.[A-Za-z0-9_-]{43}\z/', $token);
        self::assertStringNotContainsString($this->secretOf($key), $token);
        self::assertSame(['', -1], $response->cookies[LinkHandler::BAD_LINK]);
        // The redirect itself has no active registration yet: the next request has.
        self::assertNull(ActiveRegistration::current());

        $next = $this->request([], [LinkHandler::COOKIE => $token]);
        self::assertEquals($key, ActiveRegistration::current());
        self::assertFalse(ActiveRegistration::followsBadLink());
        self::assertSame(1, $next->noCache);
        self::assertSame([], $next->cookies);
    }

    public function testABadLinkClearsTheCookieAndShowsTheBadLinkTextOnce(): void
    {
        $key = $this->stored('12A3');
        $token = $this->follow($key);

        foreach (
            [
                '5.12A3.' . str_repeat('A', 22),
                '5.12A4.' . $this->secretOf($key),
                '6.12A3.' . $this->secretOf($key),
                'garbage',
            ] as $link
        ) {
            $response = $this->request([SecretLinks::PARAMETER => $link], [LinkHandler::COOKIE => $token]);
            self::assertSame('/allapot/', $response->redirect, $link);
            self::assertSame(['', -1], $response->cookies[LinkHandler::COOKIE], $link);
            self::assertSame('1', $response->cookies[LinkHandler::BAD_LINK][0], $link);
            self::assertGreaterThan(0, $response->cookies[LinkHandler::BAD_LINK][1], $link);
        }

        $shown = $this->request([], [LinkHandler::BAD_LINK => '1']);
        self::assertTrue(ActiveRegistration::followsBadLink());
        self::assertNull(ActiveRegistration::current());
        self::assertSame(['', -1], $shown->cookies[LinkHandler::BAD_LINK]);
        self::assertSame(1, $shown->noCache);
        self::assertStringContainsString('Ez a link nem érvényes.', $this->statusBlock());

        $this->request();
        self::assertFalse(ActiveRegistration::followsBadLink());
    }

    public function testAFailureWhileFollowingTheLinkStillDropsTheTokenFromTheAddress(): void
    {
        $key = $this->stored('12A3');
        $link = $key->eventId . '.' . $key->code . '.' . $this->secretOf($key);
        // The first cookie the handler sets fails, as any step of following the link could.
        $response = new class extends RecordingResponse {
            private bool $failed = false;

            public function setCookie(string $name, string $value, int $expires): void
            {
                if (!$this->failed) {
                    $this->failed = true;

                    throw new \RuntimeException('cookie could not be set');
                }
                parent::setCookie($name, $value, $expires);
            }
        };
        $url = add_query_arg([SecretLinks::PARAMETER => $link], '/allapot/');
        (new LinkHandler(new SecretLinks(), $response))
            ->handle([SecretLinks::PARAMETER => $link], [], 'GET', $url, $this->utc(self::NOW));

        self::assertSame('/allapot/', $response->redirect);
        self::assertSame(['', -1], $response->cookies[LinkHandler::COOKIE]);
        self::assertSame('1', $response->cookies[LinkHandler::BAD_LINK][0]);
    }

    public function testAnArrayInTheParameterIsABadLink(): void
    {
        $response = $this->request([SecretLinks::PARAMETER => ['x']]);

        self::assertSame('1', $response->cookies[LinkHandler::BAD_LINK][0]);
    }

    public function testTheCookieLastsUnderTwentyFourHoursFromItsIssue(): void
    {
        $key = $this->stored('12A3');
        $token = $this->follow($key, '2026-11-29 09:00:00');

        $this->request([], [LinkHandler::COOKIE => $token], '2026-11-30 08:59:59');
        self::assertEquals($key, ActiveRegistration::current(), '23:59:59 later');

        $expired = $this->request([], [LinkHandler::COOKIE => $token], '2026-11-30 10:00:00');
        self::assertNull(ActiveRegistration::current(), '25 hours later');
        self::assertFalse(ActiveRegistration::followsBadLink(), 'cleared silently');
        self::assertSame(['', -1], $expired->cookies[LinkHandler::COOKIE]);
        self::assertSame(1, $expired->noCache);
        self::assertStringContainsString('nyisd meg a jelentkezés után kapott', $this->statusBlock());

        // Following the link again issues a fresh cookie; it does not slide.
        $this->request([], [LinkHandler::COOKIE => $this->follow($key, '2026-11-30 10:00:00')], '2026-11-30 10:00:00');
        self::assertEquals($key, ActiveRegistration::current());
    }

    public function testAFutureIssueTimeIsRefused(): void
    {
        $key = $this->stored('12A3');
        $token = $this->follow($key, '2026-11-30 09:00:00');

        $this->request([], [LinkHandler::COOKIE => $token], '2026-11-30 08:00:00');
        self::assertNull(ActiveRegistration::current());
    }

    public function testATamperedCookieIsClearedSilently(): void
    {
        $key = $this->stored('12A3');
        $other = $this->stored('00A0');
        $token = $this->follow($key);
        [$v, $event, $code, $issued, $mac] = explode('.', $token);

        foreach (
            [
                $v . '.' . $event . '.' . $code . '.' . $issued . '.' . strrev($mac),
                $v . '.' . $event . '.' . $other->code . '.' . $issued . '.' . $mac,
                $v . '.' . $event . '.' . $code . '.' . ((int) $issued + 3600) . '.' . $mac,
                'v2.' . $event . '.' . $code . '.' . $issued . '.' . $mac,
                $token . 'x',
                'v1.5.12a3.' . $issued . '.' . $mac,
            ] as $tampered
        ) {
            $response = $this->request([], [LinkHandler::COOKIE => $tampered]);
            self::assertNull(ActiveRegistration::current(), $tampered);
            self::assertFalse(ActiveRegistration::followsBadLink(), $tampered);
            self::assertSame(['', -1], $response->cookies[LinkHandler::COOKIE], $tampered);
        }
    }

    public function testANewSecretRevokesTheCookieAndTheOldLink(): void
    {
        $key = $this->stored('12A3');
        $oldLink = $this->linkOf($key);
        $token = $this->follow($key);

        $issued = (new SecretLinks())->activeToken($key, $this->utc(self::NOW));
        self::assertNotNull($issued);
        self::assertEquals($key, (new SecretLinks())->verifyActiveToken($issued, $this->utc(self::NOW)));

        // Regenerated as the use case will (SecretLink::generate()), written through the repository.
        $this->inRun(function (array $input, Context $context) use ($key): void {
            $registration = $this->registrations->findForUpdate($key, $context);
            self::assertInstanceOf(Registration::class, $registration);
            $registration->secret = SecretLink::generate();
            $this->registrations->upsert($registration, $context);
        });

        self::assertNull((new SecretLinks())->verifyActiveToken($issued, $this->utc(self::NOW)));
        $response = $this->request([], [LinkHandler::COOKIE => $token]);
        self::assertNull(ActiveRegistration::current());
        self::assertSame(['', -1], $response->cookies[LinkHandler::COOKIE]);
        self::assertStringContainsString(
            '<p>A jelentkezésed állapotának megnézéséhez nyisd meg a jelentkezés után kapott e-mailben lévő'
            . ' linket.</p>',
            $this->statusBlock()
        );
        $response = $this->request([SecretLinks::PARAMETER => $oldLink]);
        self::assertSame('1', $response->cookies[LinkHandler::BAD_LINK][0]);
        $this->follow($key);
    }

    public function testBeingLoggedInWithoutTheCookieGivesNoActiveRegistration(): void
    {
        $this->stored('12A3');
        wp_set_current_user($this->fullAccessUser());

        $response = $this->request();

        self::assertNull(ActiveRegistration::current());
        self::assertSame(0, $response->noCache, 'a request without either cookie may be cached');
        self::assertStringContainsString('nyisd meg a jelentkezés után kapott', $this->statusBlock());
    }

    public function testALinkIsFollowedOnlyOnGetAndHead(): void
    {
        $key = $this->stored('12A3');

        $post = $this->request([SecretLinks::PARAMETER => $this->linkOf($key)], [], self::NOW, 'POST');
        self::assertNull($post->redirect);
        self::assertSame(1, $post->noCache);
        self::assertArrayNotHasKey(LinkHandler::COOKIE, $post->cookies);

        $head = $this->request([SecretLinks::PARAMETER => $this->linkOf($key)], [], self::NOW, 'HEAD');
        self::assertSame('/allapot/', $head->redirect);
    }

    public function testLogoutClearsTheActiveRegistration(): void
    {
        $key = $this->stored('12A3');
        $this->request([], [LinkHandler::COOKIE => $this->follow($key)]);
        self::assertNotNull(ActiveRegistration::current());

        $response = new RecordingResponse();
        (new LinkHandler(new SecretLinks(), $response))->logout();

        self::assertNull(ActiveRegistration::current());
        self::assertSame(['', -1], $response->cookies[LinkHandler::COOKIE]);
    }
}
