<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

/**
 * What the last attempt of an outermost run leaves behind for its failed audit entry.
 *
 * @internal
 */
final class RunTrace
{
    /** The moment of the last attempt, once the clock was read. */
    public ?\DateTimeImmutable $now = null;

    /** The name of the use case that threw the error, when it is not the outermost one. */
    public ?string $failedIn = null;

    /**
     * The error of the last attempt's rollback when its work may still be open on the connection: a failed
     * `ROLLBACK TO SAVEPOINT`, or a failed `ROLLBACK` after which no new connection came up.
     */
    public ?\Throwable $rollbackFailure = null;
}
