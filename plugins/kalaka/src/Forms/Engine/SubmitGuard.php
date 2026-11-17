<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The check a form submit passes before it reaches the submit seam: spam protection (S14), a rate limit
 * per client (`RateLimiter`). A logged-in person counts by their account, 20 attempts per window; a guest
 * by their address (`ClientAddress`), 100, since a shared address (a school, a church, a carrier's NAT)
 * carries many people (owner ruling 2026-11-17). Every attempt that reaches it counts, refused answers
 * and repeats included. It is core, not an extension point: a point is added only when one of our own
 * extensions needs it
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
    /** Attempts per account per window (owner, 2026-11-17). */
    public const ACCOUNT_LIMIT = 20;

    /** Attempts per address per window, a guest's (owner, 2026-11-17). */
    public const ADDRESS_LIMIT = 100;

    /** The window, in seconds (owner, 2026-11-17). */
    public const WINDOW = 600;

    private readonly RateLimiter $accounts;

    private readonly RateLimiter $addresses;

    public function __construct()
    {
        $this->accounts = new RateLimiter('submit', self::ACCOUNT_LIMIT, self::WINDOW);
        $this->addresses = new RateLimiter('submit', self::ADDRESS_LIMIT, self::WINDOW);
    }

    /**
     * Returns when the submit may go on; refuses it by throwing a `KalakaException`.
     *
     * @throws \Kalaka\Forms\Api\RateLimited
     */
    public function check(SubmitAttempt $attempt): void
    {
        $client = self::client($attempt);
        $limiter = str_starts_with($client, 'account:') ? $this->accounts : $this->addresses;
        $limiter->hit($client, Runner::current()->clock()->now());
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
