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

    /**
     * Failures a late check found while the boot was running (activation, where `wp_loaded` has already
     * fired and a module runs its check in its own `boot()`); `record()` takes them over.
     *
     * @var list<LoadFailure>
     */
    private static array $early = [];

    /**
     * @param list<LoadFailure> $failures every failure: the boot's, then the late ones
     * @param list<LoadFailure> $late the failures modules' late checks added (`close()`)
     */
    private function __construct(public readonly array $failures, public readonly array $late = [])
    {
    }

    /** Before boot has finished the site counts as closed. */
    public static function current(): self
    {
        return self::$current ?? new self([new LoadFailure('foundation', 'Not booted yet.')]);
    }

    /**
     * Called by the boot only, before any module: until it records its outcome, the site counts as
     * closed and a late check's failure waits for `record()`. [UNAPPROVED N3]
     *
     * @internal
     */
    public static function begin(): void
    {
        self::$current = null;
        self::$early = [];
    }

    /**
     * Called by the boot only.
     *
     * @internal
     * @param list<LoadFailure> $failures
     */
    public static function record(array $failures): void
    {
        self::$current = new self([...$failures, ...self::$early], self::$early);
        self::$early = [];
    }

    /**
     * Adds a failure found after the boot (a module's late check, on `wp_loaded`): the site is closed
     * from now on. Never reopens. The boot logs it with its own failures, when they start or change.
     * [UNAPPROVED N1]
     *
     * @internal called only by late checks of modules
     */
    public static function close(LoadFailure $failure): void
    {
        if (self::$current === null) {
            self::$early[] = $failure;

            return;
        }
        self::$current = new self([...self::$current->failures, $failure], [...self::$current->late, $failure]);
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
