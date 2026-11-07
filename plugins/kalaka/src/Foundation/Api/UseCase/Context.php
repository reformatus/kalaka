<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Registrations\Api\RegistrationKey;

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
     * @param \Closure(): void $assertOpen
     * @param \Closure(RegistrationKey): void $markTouched
     * @param \Closure(): list<RegistrationKey> $touchedRegistrations
     * @param \Closure(LockLevel, string, list<int|string>, bool): void $lockRow
     * @param \Closure(LockLevel, string, list<int|string>): void $checkRow
     */
    public function __construct(
        private readonly Actor $actor,
        private readonly \DateTimeImmutable $now,
        private readonly \Closure $nestedRun,
        private readonly \Closure $afterCommit,
        private readonly \Closure $lock,
        private readonly \Closure $touch,
        private readonly \Closure $change,
        private readonly \Closure $assertOpen,
        private readonly \Closure $markTouched,
        private readonly \Closure $touchedRegistrations,
        private readonly \Closure $lockRow,
        private readonly \Closure $checkRow,
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

    /**
     * @internal Only the repository bases call it: declares the row lock a read or write takes, with the
     * row's key in key order. A row this attempt already holds is no new lock. A lower level than one
     * already taken, or a lower key than the highest one at the event, quota or registration level, is
     * a programming error. `$recordOnly` records the row as held without that check and without raising
     * that highest key: only for an inserted row, which nobody else can hold.
     *
     * @param list<int|string> $key
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure
     */
    public function lockRow(LockLevel $level, string $table, array $key, bool $recordOnly = false): void
    {
        ($this->lockRow)($level, $table, $key, $recordOnly);
    }

    /**
     * @internal Only the repository bases call it: checks the row lock a read `FOR UPDATE` is about to take,
     * as `lockRow()` does, and records nothing. The read declares the row with `lockRow()` once it finds it.
     *
     * @param list<int|string> $key
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure
     */
    public function checkRow(LockLevel $level, string $table, array $key): void
    {
        ($this->checkRow)($level, $table, $key);
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

    /**
     * @internal Repositories call it before a write: a context kept past its run is refused, and after
     * the run's recorded failure (a database error or a nested run's error) the call throws that failure.
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function assertOpen(): void
    {
        ($this->assertOpen)();
    }

    /**
     * @internal Called by the repository base after it wrote a record mapped to a registration, and by
     * Foundation's `EvaluateRegistration` for the registration it is asked to evaluate.
     *
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure on a context kept past its run
     */
    public function markTouched(RegistrationKey $key): void
    {
        ($this->markTouched)($key);
    }

    /**
     * @internal For the evaluation and tests.
     *
     * @return list<RegistrationKey> the registrations written in this attempt, nested runs included, each once
     */
    public function touchedRegistrations(): array
    {
        return ($this->touchedRegistrations)();
    }
}
