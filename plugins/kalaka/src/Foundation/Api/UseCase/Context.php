<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Audit\Subject;

/** What a running use case gets from the runner. */
final class Context
{
    /**
     * @internal Built by the Runner only.
     *
     * @param \Closure(UseCase, array<string, mixed>): mixed $nestedRun
     * @param \Closure(callable(): void): void $afterCommit
     * @param \Closure(LockLevel): void $lock
     * @param \Closure(Subject): void $touch
     * @param \Closure(Subject, string, mixed, mixed, bool): void $change
     */
    public function __construct(
        private readonly Actor $actor,
        private readonly \DateTimeImmutable $now,
        private readonly \Closure $nestedRun,
        private readonly \Closure $afterCommit,
        private readonly \Closure $lock,
        private readonly \Closure $touch,
        private readonly \Closure $change,
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

    /** Records on this run's audit entry that the use case touched a record. */
    public function touch(Subject $subject): void
    {
        ($this->touch)($subject);
    }

    /**
     * Records on this run's audit entry that a field of a record changed, and the record with it.
     * The values must be JSON-encodable; a secret's are never stored.
     */
    public function change(Subject $subject, string $field, mixed $before, mixed $after, bool $secret = false): void
    {
        ($this->change)($subject, $field, $before, $after, $secret);
    }
}
