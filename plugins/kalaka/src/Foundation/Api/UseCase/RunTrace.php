<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

/**
 * What the attempts of an outermost run share, and what the last one leaves behind for its failed audit entry.
 *
 * @internal
 */
final class RunTrace
{
    /**
     * The session's own lock-wait timeout, read once before the first attempt: every attempt restores
     * this value, so a failed restore cannot pass the run's 5 s on as the next attempt's "previous" one.
     */
    public ?int $sessionLockWait = null;

    /** The moment of the last attempt, once the clock was read. */
    public ?\DateTimeImmutable $now = null;

    /** The name of the use case that threw the error, when it is not the outermost one. */
    public ?string $failedIn = null;

    /**
     * The attempt could not begin because a transaction someone else began is open on the connection
     * (MariaDB 1568): it is neither rolled back nor committed, so the failed entry goes to the error log.
     */
    public bool $foreignTransaction = false;

    /**
     * The error of the last attempt's rollback when its work may still be open on the connection: a failed
     * `ROLLBACK TO SAVEPOINT`, or a failed `ROLLBACK` after which no new connection came up.
     */
    public ?\Throwable $rollbackFailure = null;
}
