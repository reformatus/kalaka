<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * Elapsed wall time of a sender run, from `hrtime()`: the one duration in Mail that is not the
 * `Clock`'s, since it measures the request, not the domain's time. mail.md, "Sending".
 */
final class Stopwatch
{
    /** A run stops claiming after this many seconds: an HTTP `wp-cron.php` request lives under `max_execution_time`. */
    public const CAP_SECONDS = 20;

    private int $started;

    /** @var \Closure(): int */
    private readonly \Closure $nanoseconds;

    /** @param ?\Closure(): int $nanoseconds a monotonic source in nanoseconds; tests give their own */
    public function __construct(?\Closure $nanoseconds = null)
    {
        $this->nanoseconds = $nanoseconds ?? static fn (): int => hrtime(true);
        $this->started = ($this->nanoseconds)();
    }

    public function start(): void
    {
        $this->started = ($this->nanoseconds)();
    }

    public function capReached(): bool
    {
        return (($this->nanoseconds)() - $this->started) >= self::CAP_SECONDS * 1_000_000_000;
    }
}
