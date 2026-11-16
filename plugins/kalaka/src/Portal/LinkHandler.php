<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Registrations\Api\SecretLinks;

/**
 * The secret link and the active-registration cookie (registrations-and-identity.md, "Access"; SB-5, SB-7,
 * SB-10, SB-12), handled at `init`, before any output or third-party asset, so the token never reaches a
 * `Referer`:
 *
 * - A request carrying `kalaka_link` is answered with a 302 to the same URL without it, never cached. A
 *   link that matches sets the cookie (a session cookie holding `SecretLinks::activeToken()`); any other
 *   clears the cookie and sets the bad-link flag, which the next request reads once and clears.
 * - Otherwise a cookie that `SecretLinks::verifyActiveToken()` accepts names the active registration; an
 *   expired, revoked or malformed one is cleared silently. A request carrying either cookie is never
 *   cached: a theme or a builder can place a participant block where no content check sees it.
 * - Being logged in sets nothing: without the cookie there is no active registration (SB-12).
 */
final class LinkHandler
{
    /** The cookie of the active registration. */
    public const COOKIE = 'kalaka_active';

    /** The one-shot flag of a link that did not match. */
    public const BAD_LINK = 'kalaka_badlink';

    /** How long the bad-link flag lives if the redirect is not followed, in seconds. */
    private const BAD_LINK_LIFETIME = 60;

    public function __construct(
        private readonly SecretLinks $links = new SecretLinks(),
        private readonly Response $response = new HttpResponse(),
    ) {
    }

    /**
     * @param array<string, mixed> $query the request's query parameters, unslashed
     * @param array<string, mixed> $cookies the request's cookies, unslashed
     * @param string $url the request's URL (path and query)
     */
    public function handle(array $query, array $cookies, string $method, string $url, \DateTimeImmutable $now): void
    {
        ActiveRegistrationState::set(null, false);
        $hasCookie = isset($cookies[self::COOKIE]) || isset($cookies[self::BAD_LINK]);
        if ($hasCookie || isset($query[SecretLinks::PARAMETER])) {
            $this->response->noCache();
        }

        if (isset($query[SecretLinks::PARAMETER]) && in_array($method, ['GET', 'HEAD'], true)) {
            try {
                $this->follow($query[SecretLinks::PARAMETER], $now);
            } catch (\Throwable $error) {
                // The token must leave the address (and so the browser's history) whatever happens: a failed
                // lookup is logged and reads as a bad link.
                Boundary::report('secret link', $error);
                $this->markBadLink($now);
            }
            $this->response->redirect(remove_query_arg(SecretLinks::PARAMETER, $url));

            return;
        }

        $badLink = isset($cookies[self::BAD_LINK]);
        if ($badLink) {
            $this->response->setCookie(self::BAD_LINK, '', -1);
        }
        $active = null;
        if (isset($cookies[self::COOKIE])) {
            $token = $cookies[self::COOKIE];
            $active = is_string($token) && LoadState::current()->isOpen()
                ? $this->links->verifyActiveToken($token, $now)
                : null;
            if ($active === null && LoadState::current()->isOpen()) {
                $this->response->setCookie(self::COOKIE, '', -1);
            }
        }
        ActiveRegistrationState::set($active, $badLink);
    }

    /** Sets the cookie for a link that matches; clears it and sets the bad-link flag for any other. */
    private function follow(mixed $link, \DateTimeImmutable $now): void
    {
        // While the site is closed nothing is read: the token is only dropped from the address.
        if (!LoadState::current()->isOpen()) {
            return;
        }
        $key = is_string($link) ? $this->links->fromLink($link) : null;
        $token = $key === null ? null : $this->links->activeToken($key, $now);
        if ($token !== null) {
            $this->response->setCookie(self::COOKIE, $token, 0);
            $this->response->setCookie(self::BAD_LINK, '', -1);

            return;
        }
        $this->markBadLink($now);
    }

    /** Clears the active registration and sets the one-shot bad-link flag. */
    private function markBadLink(\DateTimeImmutable $now): void
    {
        $this->response->setCookie(self::COOKIE, '', -1);
        $this->response->setCookie(self::BAD_LINK, '1', $now->getTimestamp() + self::BAD_LINK_LIFETIME);
    }

    /** `wp_logout`: a logout on a shared device clears the active registration too. */
    public function logout(): void
    {
        $this->response->setCookie(self::COOKIE, '', -1);
        ActiveRegistrationState::set(null, false);
    }
}
