<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;

/** What a running use case gets from the runner. */
final class Context
{
    /**
     * @internal Built by the Runner only.
     *
     * @param \Closure(UseCase, array<string, mixed>): mixed $nestedRun
     * @param \Closure(callable(): void): void $afterCommit
     * @param \Closure(LockLevel): void $lock
     */
    public function __construct(
        private readonly Actor $actor,
        private readonly \DateTimeImmutable $now,
        private readonly \Closure $nestedRun,
        private readonly \Closure $afterCommit,
        private readonly \Closure $lock,
    ) {
    }

    /** The actor given to `Runner::run()`. */
    public function actor(): Actor
    {
        return $this->actor;
    }

    /** The moment of this attempt, the same in nested runs. */
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Runs a nested use case in this transaction, as the same actor.
     *
     * @param array<string, mixed> $input
     */
    public function run(UseCase $useCase, array $input): mixed
    {
        return ($this->nestedRun)($useCase, $input);
    }

    /**
     * Queues a WordPress-side step to run after the outermost commit, in registration order.
     *
     * @param callable(): void $step
     */
    public function afterCommit(callable $step): void
    {
        ($this->afterCommit)($step);
    }

    /**
     * Declares the lock level a repository is about to take with a `FOR UPDATE` read;
     * a level lower than one already taken in this transaction is a programming error.
     */
    public function lock(LockLevel $level): void
    {
        ($this->lock)($level);
    }
}
