<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\RateLimited;
use Kalaka\Foundation\Api\Database\NamedLock;
use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * A rate limit on a public route (S14): at most `limit` attempts per client in each fixed window of
 * `window` seconds, counted per `scope` (the submit route has one; S74's draft routes get their own).
 *
 * The count is a transient, WordPress-owned data: code outside a use case never writes our own tables
 * (foundation.md, "Errors"), and a use case would audit every attempt. Its name is a keyed hash (HMAC with
 * `wp_salt()`) of the scope, the window and the client, so no address is stored, and one window's name
 * says nothing about another's. It expires when its window ends; WordPress's daily cleanup removes it.
 *
 * Reading and writing the count is not atomic, so a named lock per client serialises it, taken without
 * waiting: an attempt that arrives while the same client's previous one is being counted (a parallel
 * burst) is refused for a second. Any other failure of the count lets the attempt through and is logged:
 * a broken counter must never stop a registration.
 *
 * It runs outside any transaction, before the route's use case.
 *
 * [UNAPPROVED #196 N3] the transient, its keyed-hash name, the lock and failing open.
 */
final class RateLimiter
{
    /**
     * @param non-empty-string $scope `[a-z_]+`
     * @param positive-int $limit attempts per window
     * @param positive-int $window seconds
     */
    public function __construct(
        private readonly string $scope,
        private readonly int $limit,
        private readonly int $window,
    ) {
    }

    /**
     * Counts one attempt of the client at the time; refuses it when the window's attempts are used up.
     *
     * @throws RateLimited
     */
    public function hit(string $client, \DateTimeImmutable $now): void
    {
        $time = $now->getTimestamp();
        $bucket = intdiv($time, $this->window);
        $retryAfter = max(1, ($bucket + 1) * $this->window - $time);
        $key = hash_hmac('sha256', $this->scope . '|' . $bucket . '|' . $client, wp_salt('nonce'));

        try {
            $lock = new NamedLock('rl_' . substr($key, 0, 32));
            if (!$lock->acquire()) {
                $refused = new RateLimited(1);
                throw $refused;
            }
            try {
                $this->count(self::transient($key), $retryAfter);
            } finally {
                $lock->release();
            }
        } catch (RateLimited $refused) {
            throw $refused;
        } catch (\Throwable $failure) {
            Boundary::report('rate limit ' . $this->scope, $failure);
        }
    }

    /** The transient that holds a client's count in one window of one scope. */
    public static function transient(string $key): string
    {
        return 'kalaka_rl_' . substr($key, 0, 40);
    }

    /**
     * @param positive-int $retryAfter
     * @throws RateLimited
     */
    private function count(string $transient, int $retryAfter): void
    {
        /** @var mixed $stored */
        $stored = get_transient($transient);
        $count = is_numeric($stored) ? (int) $stored : 0;
        if ($count >= $this->limit) {
            $refused = new RateLimited($retryAfter);
            throw $refused;
        }
        set_transient($transient, $count + 1, $retryAfter);
    }
}
