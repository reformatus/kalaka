<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

use Kalaka\Foundation\Api\Error\SiteClosed;

/**
 * The outcome of this request's boot. If anything failed to load, the site is
 * closed: every event is treated as unpublished, all public access is refused,
 * and mail decisions and sending stop, and every use case is refused (`requireOpen()`). Stored event
 * states do not change.
 */
final class LoadState
{
    private static ?self $current = null;

    /** @param list<LoadFailure> $failures */
    private function __construct(public readonly array $failures)
    {
    }

    /** Before boot has finished the site counts as closed. */
    public static function current(): self
    {
        return self::$current ?? new self([new LoadFailure('foundation', 'Not booted yet.')]);
    }

    /**
     * Called by the boot only.
     *
     * @internal
     * @param list<LoadFailure> $failures
     */
    public static function record(array $failures): void
    {
        self::$current = new self($failures);
    }

    public function isOpen(): bool
    {
        return $this->failures === [];
    }

    /**
     * Called at every public entry point, and by the runner before every use case.
     *
     * @throws SiteClosed while the site is closed
     */
    public function requireOpen(): void
    {
        if (!$this->isOpen()) {
            throw new SiteClosed();
        }
    }
}
