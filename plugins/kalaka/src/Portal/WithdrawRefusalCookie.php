<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Portal\Api\WithdrawUnavailable;

/**
 * The flash cookie of a refused no-script withdrawal post: the route sets it on its 303 to the clean return
 * address, and the next page request reads it once and expires it. Its value is a refusal code and nothing else,
 * one of the known ones or the generic `kalaka_system_failure`; any other value is dropped.
 *
 * It is the same mechanism as the card payment route's `CardRefusalCookie` (a separate class only until that slice
 * lands; whichever lands second makes them one portal flash cookie). [UNAPPROVED N660]
 */
final class WithdrawRefusalCookie
{
    public const NAME = 'kalaka_withdraw_refusal';

    /** How long it lives if the redirect is not followed, in seconds. */
    public const LIFETIME = 120;

    private const GENERIC = 'kalaka_system_failure';

    /** The code to keep for an error's code: a known refusal as it is, anything else the generic one. */
    public static function codeFor(string $errorCode): string
    {
        return self::isKnown($errorCode) ? $errorCode : self::GENERIC;
    }

    /** The `Set-Cookie` header value that carries the code. */
    public static function header(string $errorCode): string
    {
        return self::NAME . '=' . self::codeFor($errorCode) . '; Max-Age=' . self::LIFETIME
            . '; Path=/; HttpOnly; SameSite=Lax' . (is_ssl() ? '; Secure' : '');
    }

    /**
     * On `template_redirect`: holds the cookie's code for the request, when it is a known one, and expires the
     * cookie either way.
     *
     * @param array<string, mixed> $cookies the request's cookies, unslashed
     */
    public static function read(array $cookies, Response $response): void
    {
        WithdrawRefusalState::set(null);
        if (!isset($cookies[self::NAME])) {
            return;
        }
        $value = $cookies[self::NAME];
        if (is_string($value) && self::isKnown($value)) {
            WithdrawRefusalState::set($value);
        }
        $response->setCookie(self::NAME, '', -1);
    }

    private static function isKnown(string $code): bool
    {
        return in_array($code, [
            (new WithdrawUnavailable())->errorCode(),
            (new \Kalaka\Registrations\Api\StatusActRefused())->errorCode(),
            self::GENERIC,
        ], true);
    }
}
