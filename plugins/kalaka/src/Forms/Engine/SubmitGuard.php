<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
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

    private readonly RateLimiter $handoffAccounts;

    private readonly RateLimiter $handoffAddresses;

    public function __construct()
    {
        $this->handoffAccounts = new RateLimiter('handoff', self::ACCOUNT_LIMIT, self::WINDOW);
        $this->handoffAddresses = new RateLimiter('handoff', self::ADDRESS_LIMIT, self::WINDOW);
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

    /**
     * Returns when a link's or the quiz's handoff may keep its answers as a draft (S74, N6: a request that
     * writes a row counts like a submit, with the same budget per client, in a scope of its own); refuses it
     * by throwing a `KalakaException`.
     *
     * @throws \Kalaka\Forms\Api\RateLimited
     */
    public function checkHandoff(Actor $actor): void
    {
        $client = self::clientOf($actor);
        $limiter = str_starts_with($client, 'account:') ? $this->handoffAccounts : $this->handoffAddresses;
        $limiter->hit($client, Runner::current()->clock()->now());
    }

    /** Whom an attempt counts for (N2, owner 2026-11-18): the account when logged in, else the address. */
    public static function client(SubmitAttempt $attempt): string
    {
        return self::clientOf($attempt->actor);
    }

    /** The same, for an actor alone. */
    public static function clientOf(Actor $actor): string
    {
        if ($actor instanceof AccountActor) {
            return 'account:' . $actor->userId;
        }

        return 'address:' . (ClientAddress::current() ?? 'unknown');
    }
}
