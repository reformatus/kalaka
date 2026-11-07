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
     * The attempt could not begin because a transaction someone else began is open on the connection
     * (MariaDB 1568): it is neither rolled back nor committed, so the failed entry goes to the error log.
     */
    public bool $foreignTransaction = false;
}
