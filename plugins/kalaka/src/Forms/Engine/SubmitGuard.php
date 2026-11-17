<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The check a form submit passes before it reaches the submit seam: spam protection (S14), a rate limit
 * per client (`RateLimiter`). A logged-in person counts by their account, a guest by their address
 * (`ClientAddress`). Every attempt that reaches it counts, refused answers and repeats included. It is
 * core, not an extension point: a point is added only when one of our own extensions needs it
 * (modules-and-extensions.md, "Contracts"), and an interface only where there are several
 * implementations (foundation.md, "Layers").
 *
 * The CAPTCHA is not here: it guards account creation, on WordPress's registration page, through a plugin
 * (owner ruling, 2026-11-14).
 *
 * It runs outside any transaction, in the submit route, before the submit seam reads the form. It refuses by
 * throwing a `KalakaException` (`RateLimited`); it never writes to our tables.
 */
final class SubmitGuard
{
    /** [UNAPPROVED #196 N1] attempts per client per window. */
    public const LIMIT = 20;

    /** [UNAPPROVED #196 N1] the window, in seconds. */
    public const WINDOW = 600;

    private readonly RateLimiter $limiter;

    public function __construct(?RateLimiter $limiter = null)
    {
        $this->limiter = $limiter ?? new RateLimiter('submit', self::LIMIT, self::WINDOW);
    }

    /**
     * Returns when the submit may go on; refuses it by throwing a `KalakaException`.
     *
     * @throws \Kalaka\Forms\Api\RateLimited
     */
    public function check(SubmitAttempt $attempt): void
    {
        $this->limiter->hit(self::client($attempt), Runner::current()->clock()->now());
    }

    /** [UNAPPROVED #196 N2] whom an attempt counts for: the account when logged in, else the address. */
    public static function client(SubmitAttempt $attempt): string
    {
        if ($attempt->actor instanceof AccountActor) {
            return 'account:' . $attempt->actor->userId;
        }

        return 'address:' . (ClientAddress::current() ?? 'unknown');
    }
}
