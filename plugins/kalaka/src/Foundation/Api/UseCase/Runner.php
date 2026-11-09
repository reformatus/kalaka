<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\Side;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\AuditEntry;
use Kalaka\Foundation\Api\Audit\AuditWriter;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Audit\EntryHeader;
use Kalaka\Foundation\Api\Audit\ErrorRecord;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Database\Statements;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\ErrorLog;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Foundation\LockKey;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Runs use cases: one transaction each, rollback on any error, retries, nested runs.
 * foundation.md, "The use-case runner".
 */
final class Runner
{
    /** The first attempt and three retries. */
    private const MAX_ATTEMPTS = 4;

    /** MariaDB: "Transaction characteristics can't be changed while a transaction is in progress". */
    private const OPEN_TRANSACTION = 1568;

    /** How long an attempt waits for a row lock before MariaDB gives up with error 1205. */
    private const LOCK_WAIT_SECONDS = 5;

    private static ?Runner $current = null;

    /** The attempt in progress, in any runner of this request. */
    private static ?RunState $active = null;

    /** @var \WeakMap<KalakaException, true>|null the errors a run threw after recording them */
    private static ?\WeakMap $recorded = null;

    private readonly Statements $statements;

    private readonly CoarsePermission $permission;

    private readonly AuditWriter $audit;

    private readonly Evaluation $evaluation;

    private readonly BinlogCheck $binlog;

    /**
     * @param bool $insideOpenTransaction tests only: the harness already holds a transaction, so the
     *                                    outermost run uses a savepoint instead of its own transaction
     * @param ?Evaluation $evaluation what brings the touched registrations up to date; none: no rules
     * @param ?BinlogCheck $binlog whether writes under READ COMMITTED would fail; none: a check of its own
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly bool $insideOpenTransaction = false,
        ?Evaluation $evaluation = null,
        ?BinlogCheck $binlog = null,
    ) {
        $this->statements = new Statements();
        $this->permission = new CoarsePermission();
        $this->audit = new AuditWriter($this->statements);
        $this->evaluation = $evaluation ?? new Evaluation(new DecisionRules());
        $this->binlog = $binlog ?? new BinlogCheck();
    }

    /** The production runner; delivery code uses this. */
    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('Foundation has not booted: no runner yet.');
    }

    /** @internal Foundation's boot publishes the production runner. */
    public static function publish(self $runner): void
    {
        self::$current = $runner;
    }

    /**
     * @internal `DatabaseError::check()` calls it before it throws: inside an attempt the error becomes
     * the run's failure, unless one is recorded already. Outside any attempt it does nothing.
     */
    public static function databaseFailed(KalakaException $error): void
    {
        if (self::$active !== null) {
            self::$active->failure ??= $error;
        }
    }

    /**
     * Whether a run threw this error after recording it: in a failed audit entry, in the error log
     * when that entry could not be written, or not at all when it is not audited (InvalidInput). The
     * error boundary logs only an error that no run recorded.
     */
    public static function recorded(KalakaException $error): bool
    {
        return isset(self::$recorded[$error]);
    }

    /**
     * @internal `DatabaseError::check()` reads the attempt in progress, if any, to compare its connection
     * thread id with the current one.
     */
    public static function activeRun(): ?RunState
    {
        return self::$active;
    }

    /**
     * Runs a use case as the actor and returns what its `execute` returned.
     *
     * @param array<string, mixed> $input
     * @throws KalakaException anything else that goes wrong is wrapped in a SystemFailure
     */
    public function run(UseCase $useCase, array $input, Actor $actor): mixed
    {
        if (self::$active !== null) {
            // Nested use cases go through Context::run(); the outer run must not survive this.
            $cause = new \LogicException('Runner::run() called inside a running use case.');
            $error = new SystemFailure(null, $cause);
            self::$active->failure ??= $error;
            throw $error;
        }

        $side = null;
        $declared = [];
        $trace = new RunTrace();
        try {
            $side = self::sideOf($useCase);
            $input = self::checkedInput($useCase, $input);
            $declared = self::declaredSubjects($useCase, $input);
            $this->permission->check($useCase, $side, $input, $actor);
            if ($this->binlog->refuses()) {
                // Before the transaction and outside the retries: every write would fail with 1665.
                $error = new SystemFailure(BinlogCheck::REFUSAL);
                throw $error;
            }
            $header = new EntryHeader($useCase::name(), $side, $actor, self::reasonOf($useCase, $input), $declared);
            [$result, $steps] = $this->runWithRetries($useCase, $input, $actor, $header, $trace);
        } catch (\Throwable $e) {
            $error = self::asKalaka($e);
            $header = new EntryHeader($useCase::name(), $side ?? $actor->side(), $actor, null, $declared);
            $this->auditFailure($useCase, $header, $error, $trace);
            self::$recorded ??= new \WeakMap();
            self::$recorded[$error] = true;
            throw $error;
        }

        foreach ($steps as [$name, $step]) {
            try {
                $step();
            } catch (\Throwable $e) {
                ErrorLog::failure('after-commit step of ' . $name, $e);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{mixed, list<array{string, callable(): void}>}
     */
    private function runWithRetries(
        UseCase $useCase,
        array $input,
        Actor $actor,
        EntryHeader $header,
        RunTrace $trace,
    ): array {
        for ($attempt = 1;; $attempt++) {
            try {
                return $this->attempt($useCase, $input, $actor, $header, $trace);
            } catch (KalakaException $e) {
                // A failed rollback may have left the attempt's work open on the connection: no retry on it.
                $retried = $attempt < self::MAX_ATTEMPTS && self::isRetried($e, $actor)
                    && $trace->rollbackFailure === null;
                if (!$retried) {
                    throw $e;
                }
            }

            usleep(random_int(10, 50) * 1000 * $attempt);
        }
    }

    private static function isRetried(KalakaException $error, Actor $actor): bool
    {
        return $error->isRetryable() || ($error instanceof VersionConflict && $actor instanceof SystemActor);
    }

    /**
     * One attempt from scratch: begin at READ COMMITTED (a savepoint in the harness, where the level cannot
     * change), execute, evaluate, write the audit entries, commit; an explicit rollback on any exception.
     * The thread id is recorded before the attempt's first statement; the check after each statement, the
     * commit or release included, compares it while the attempt is active. A reconnect at the commit is
     * no failure when the new connection finds the attempt's audit entry. The session's lock-wait timeout
     * is set to `LOCK_WAIT_SECONDS` before the transaction and restored after it, committed or rolled back,
     * to the session's own value as read before the run's first attempt.
     *
     * @param array<string, mixed> $input
     * @return array{mixed, list<array{string, callable(): void}>}
     */
    private function attempt(
        UseCase $useCase,
        array $input,
        Actor $actor,
        EntryHeader $header,
        RunTrace $trace,
    ): array {
        $state = new RunState();
        $state->useCase = $useCase::name();
        self::$active = $state;
        $trace->rollbackFailure = null;
        $beginning = false;
        $begun = false;
        $previousTimeout = null;
        $result = null;
        $entryId = null;
        ObjectCache::clear();

        try {
            $now = $this->clock->now();
            $trace->now = $now;
            $entry = $header->entry($now);
            $state->threadId = $this->statements->threadId();
            $trace->sessionLockWait ??= $this->statements->lockWaitTimeout();
            // Restored even when the set fails: wpdb may have re-sent it on a new connection.
            $previousTimeout = $trace->sessionLockWait;
            $this->statements->setLockWaitTimeout(self::LOCK_WAIT_SECONDS);
            if ($this->insideOpenTransaction) {
                $this->statements->savepoint('kalaka_run');
            } else {
                // From here on a failure rolls back: after a reconnect, wpdb re-sends START TRANSACTION.
                $beginning = true;
                $this->statements->begin(true);
            }
            $begun = true;
            $context = $this->context($useCase, $actor, $now, $state, $entry);
            $result = $useCase->execute($input, $context);
            self::throwRecorded($state);
            $this->evaluation->run($context);
            self::throwRecorded($state);
            $entryId = $this->audit->write($entry);
            if ($this->insideOpenTransaction) {
                $this->statements->releaseSavepoint('kalaka_run');
            } else {
                $this->statements->commit();
            }
        } catch (\Throwable $e) {
            $committed = $entryId !== null
                && $this->statements->threadId() !== $state->threadId
                && $this->entryExists($entryId, $useCase::name());
            if ($committed) {
                // The server committed before the connection dropped; wpdb re-sent the commit on a new one.
                return [$result, $state->steps];
            }
            $error = $state->failure ?? self::asKalaka($e);
            // A transaction someone else left open is neither rolled back nor committed.
            $foreign = !$begun && self::isOpenTransactionRefusal($error);
            if (($begun || $beginning) && !$foreign) {
                $this->rollback($trace);
            }
            $trace->failedIn = $state->failedIn;
            if ($foreign) {
                $trace->foreignTransaction = true;
            }
            throw $error;
        } finally {
            $state->open = false;
            self::$active = null;
            if ($previousTimeout !== null) {
                $this->restoreLockWaitTimeout($previousTimeout);
            }
        }

        return [$result, $state->steps];
    }

    /**
     * After a reconnect at the commit: whether the attempt's audit entry is on the new connection, which
     * proves that its transaction committed. It is matched by id, this request's correlation id and the use
     * case: after a server restart an id may be handed out again. Detached from the attempt, so the read is
     * not taken for the reconnect again; a read that fails proves nothing.
     */
    private function entryExists(int $entryId, string $action): bool
    {
        self::$active = null;
        try {
            $sql = $this->statements->prepare(
                'SELECT id FROM %i WHERE id = %d AND correlation_id = %s AND action = %s',
                [$this->statements->prefixed('kalaka_audit_entries'), $entryId, Correlation::id(), $action],
            );

            return $this->statements->select($sql) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** MariaDB's 1568: the isolation level cannot change, because a transaction is already open on the connection. */
    private static function isOpenTransactionRefusal(KalakaException $error): bool
    {
        $cause = $error->getPrevious();

        return $cause instanceof \RuntimeException && $cause->getCode() === self::OPEN_TRANSACTION;
    }

    /**
     * Detached from the attempt, so neither a reconnect nor an error is taken for the run's failure: a
     * committed run stays committed, and a failed one keeps its own error. PHP's error log gets a failure.
     */
    private function restoreLockWaitTimeout(int $seconds): void
    {
        try {
            $this->statements->setLockWaitTimeout($seconds);
        } catch (\Throwable $e) {
            ErrorLog::failure('restoring the lock-wait timeout', $e);
        }
    }

    /**
     * After the rollback: the failed entry in a transaction of its own (decision D12). If even that
     * cannot be written, PHP's error log gets one line and the original error is thrown as before. So it
     * does when the rollback failed and the failed attempt's work may still be open on the connection:
     * an entry written then could commit that work.
     */
    private function auditFailure(UseCase $useCase, EntryHeader $header, KalakaException $error, RunTrace $trace): void
    {
        if (!$error->isAudited()) {
            return;
        }
        if ($trace->foreignTransaction) {
            // Beginning the entry's own transaction would commit someone else's open work: the error log only.
            ErrorLog::failure(
                $useCase::name(),
                $error,
                'audit entry not written: a transaction another plugin left open',
            );

            return;
        }
        if ($trace->rollbackFailure !== null) {
            ErrorLog::failure($useCase::name(), $error, 'audit entry not written: the rollback failed');

            return;
        }

        try {
            $entry = $header->failed(
                $trace->now ?? $this->clock->now(),
                ErrorRecord::json($error, $trace->failedIn ?? $useCase::name()),
            );
            if ($this->insideOpenTransaction) {
                $this->statements->savepoint('kalaka_audit');
            } else {
                // It only inserts: the session's own level (decision D6).
                $this->statements->begin(false);
            }
            try {
                $this->audit->write($entry);
                if ($this->insideOpenTransaction) {
                    $this->statements->releaseSavepoint('kalaka_audit');
                } else {
                    $this->statements->commit();
                }
            } catch (\Throwable $writeError) {
                try {
                    if ($this->insideOpenTransaction) {
                        $this->statements->rollbackToSavepoint('kalaka_audit');
                    } else {
                        $this->statements->rollback();
                    }
                } catch (\Throwable) {
                    // The write error is the one that matters.
                }
                throw $writeError;
            }
        } catch (\Throwable $writeError) {
            ErrorLog::failure(
                $useCase::name(),
                $error,
                'audit entry not written: ' . ErrorLog::chain($writeError),
            );
        }
    }

    /**
     * A failing rollback never replaces the original exception; PHP's error log is the last fallback.
     * The attempt is detached first, so the rollback's own error is never recorded as the run's failure.
     * When `ROLLBACK` fails, the runner closes the connection, which makes the server discard the
     * transaction, and connects again. When that fails too, or `ROLLBACK TO SAVEPOINT` fails, the
     * trace keeps the error and no failed entry is written.
     */
    private function rollback(RunTrace $trace): void
    {
        self::$active = null;
        try {
            if ($this->insideOpenTransaction) {
                $this->statements->rollbackToSavepoint('kalaka_run');
            } else {
                $this->statements->rollback();
            }
        } catch (\Throwable $e) {
            ErrorLog::failure('rollback', $e);
            if ($this->insideOpenTransaction || !$this->reconnect()) {
                $trace->rollbackFailure = $e;
            }
        } finally {
            ObjectCache::clear();
        }
    }

    private function reconnect(): bool
    {
        try {
            return $this->statements->reconnect();
        } catch (\Throwable) {
            return false;
        }
    }

    private function context(
        UseCase $useCase,
        Actor $actor,
        \DateTimeImmutable $now,
        RunState $state,
        AuditEntry $entry,
    ): Context {
        return new Context(
            $actor,
            $now,
            fn (UseCase $nested, array $input): mixed => $this->runNested(
                $nested,
                $input,
                $actor,
                $now,
                $state,
                $entry,
            ),
            static function (callable $step) use ($useCase, $state): void {
                self::assertOpen($state);
                $state->steps[] = [$useCase::name(), $step];
            },
            static function (LockLevel $level) use ($state): void {
                self::assertOpen($state);
                self::checkLevel($state, $level);
                $state->highestLock = $level;
            },
            static function (Subject $subject) use ($state, $entry): void {
                self::assertOpen($state);
                $entry->touch($subject);
            },
            static function (
                Subject $subject,
                string $field,
                mixed $before,
                mixed $after,
                bool $secret,
            ) use (
                $state,
                $entry,
            ): void {
                self::assertOpen($state);
                $entry->change($subject, $field, $before, $after, $secret);
            },
            static function () use ($state): void {
                self::assertOpen($state);
            },
            static function (RegistrationKey $key) use ($state): void {
                self::assertOpen($state);
                $state->touched[self::registrationId($key)] ??= $key;
            },
            static fn (): array => array_values($state->touched),
            static function (
                LockLevel $level,
                string $table,
                array $key,
                bool $recordOnly,
                ?RegistrationKey $registration,
            ) use ($state): void {
                self::assertOpen($state);
                self::lockRow($state, $level, $table, $key, $recordOnly, $registration);
            },
            static function (LockLevel $level, string $table, array $key) use ($state): void {
                self::assertOpen($state);
                $key = LockKey::normalise($key);
                if (!isset($state->heldRows[LockKey::identity($table, $key)])) {
                    self::checkRow($state, $level, $table, $key);
                }
            },
            static fn (RegistrationKey $key): bool => isset($state->heldRegistrations[self::registrationId($key)]),
        );
    }

    private static function checkLevel(RunState $state, LockLevel $level): void
    {
        if ($state->highestLock !== null && $level->value < $state->highestLock->value) {
            $cause = new \LogicException(
                'Lock order violated: ' . $level->name . ' requested after ' . $state->highestLock->name . '.'
            );
            $error = new SystemFailure(null, $cause);
            throw $error;
        }
    }

    /**
     * The row guard: a row the attempt already holds is no new lock; otherwise the level, then, at the
     * event, quota and registration levels, the key against the highest one held at that level. An
     * inserted row is recorded with `$recordOnly`, unchecked, and does not raise that highest key. A held
     * row at the registration level with the registration its record maps to makes
     * `Context::holdsRegistration()` true for that key.
     *
     * @param list<int|string> $key
     */
    private static function lockRow(
        RunState $state,
        LockLevel $level,
        string $table,
        array $key,
        bool $recordOnly,
        ?RegistrationKey $registration,
    ): void {
        $key = LockKey::normalise($key);
        $id = LockKey::identity($table, $key);
        if (!isset($state->heldRows[$id])) {
            self::holdRow($state, $level, $table, $key, $id, $recordOnly);
        }
        if ($registration !== null && $level === LockLevel::Registration) {
            $state->heldRegistrations[self::registrationId($registration)] = true;
        }
    }

    /** @param list<int|string> $key normalised */
    private static function holdRow(
        RunState $state,
        LockLevel $level,
        string $table,
        array $key,
        string $id,
        bool $recordOnly,
    ): void {
        if (!$recordOnly) {
            self::checkRow($state, $level, $table, $key);
        }

        $highest = $state->highestKey[$level->value] ?? null;
        $state->heldRows[$id] = true;
        if (!$recordOnly && ($highest === null || LockKey::compare($table, $key, $highest[0], $highest[1]) > 0)) {
            $state->highestKey[$level->value] = [$table, $key];
        }
        if ($state->highestLock === null || $level->value > $state->highestLock->value) {
            $state->highestLock = $level;
        }
    }

    /**
     * The level, then, at the event, quota and registration levels, the key against the highest one held
     * at that level, for a row the attempt does not hold.
     *
     * @param list<int|string> $key normalised
     */
    private static function checkRow(RunState $state, LockLevel $level, string $table, array $key): void
    {
        self::checkLevel($state, $level);
        $highest = $state->highestKey[$level->value] ?? null;
        if (
            $level !== LockLevel::Other
            && $highest !== null
            && LockKey::compare($table, $key, $highest[0], $highest[1]) < 0
        ) {
            $cause = new \LogicException(
                'Lock order violated: ' . $level->name . ' row ' . $table . ' (' . implode(', ', $key)
                . ') requested after ' . $highest[0] . ' (' . implode(', ', $highest[1]) . ').'
            );
            $error = new SystemFailure(null, $cause);
            throw $error;
        }
    }

    private static function registrationId(RegistrationKey $key): string
    {
        return $key->eventId . "\0" . $key->code;
    }

    /**
     * A nested run joins the transaction, uses the parent's actor and skips the coarse check.
     * Its first error ends the whole use case, even if a parent catches it: the context then refuses
     * writes, locks and nested runs. An InvalidInput becomes a SystemFailure. It collects an audit
     * entry of its own, linked to its parent's.
     *
     * @param array<string, mixed> $input
     */
    private function runNested(
        UseCase $useCase,
        array $input,
        Actor $actor,
        \DateTimeImmutable $now,
        RunState $state,
        AuditEntry $parent,
    ): mixed {
        // Outside the try: a refusal is the parent's failure, not this use case's.
        self::assertOpen($state);
        try {
            $side = self::sideOf($useCase);
            $input = self::checkedInput($useCase, $input);
            $header = new EntryHeader(
                $useCase::name(),
                $side,
                $actor,
                self::reasonOf($useCase, $input),
                self::declaredSubjects($useCase, $input),
            );
            $entry = $header->entry($now);
            $parent->addChild($entry);

            return $useCase->execute($input, $this->context($useCase, $actor, $now, $state, $entry));
        } catch (\Throwable $e) {
            $error = self::asKalaka($e);
            if ($error instanceof InvalidInput) {
                // The parent built this input, so a mismatch is a programming error.
                $error = new SystemFailure(null, $error);
            }
            $state->failure ??= $error;
            if ($state->failure === $error && $state->failedIn === null) {
                // Its own error, or a database error recorded inside it.
                $state->failedIn = $useCase::name();
            }
            throw $error;
        }
    }

    /** Refuses a context kept past its run, and, once a failure is recorded, throws that failure itself. */
    private static function assertOpen(RunState $state): void
    {
        if (!$state->open) {
            $cause = new \LogicException('The context is used after its run has ended.');
            $error = new SystemFailure(null, $cause);
            throw $error;
        }
        self::throwRecorded($state);
    }

    /** The recorded failure decides the outcome, whatever the code since did with it. */
    private static function throwRecorded(RunState $state): void
    {
        if ($state->failure !== null) {
            throw $state->failure;
        }
    }

    private static function sideOf(UseCase $useCase): Side
    {
        $admin = $useCase instanceof AdminUseCase;
        $portal = $useCase instanceof PortalUseCase;
        if ($admin === $portal) {
            $cause = new \LogicException(
                $useCase::name() . ' must implement exactly one of AdminUseCase and PortalUseCase.'
            );
            $error = new SystemFailure(null, $cause);
            throw $error;
        }

        return $admin ? Side::Admin : Side::Portal;
    }

    /**
     * Checks the input against the use case's schema and returns it sanitized.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function checkedInput(UseCase $useCase, array $input): array
    {
        $schema = $useCase->inputSchema();

        $valid = rest_validate_value_from_schema($input, $schema, 'input');
        if (is_wp_error($valid)) {
            $cause = new \InvalidArgumentException($valid->get_error_message());
            $error = new InvalidInput(null, $cause);
            throw $error;
        }

        $sanitized = rest_sanitize_value_from_schema($input, $schema, 'input');
        if (is_wp_error($sanitized)) {
            $cause = new \InvalidArgumentException($sanitized->get_error_message());
            $error = new InvalidInput(null, $cause);
            throw $error;
        }
        if (!is_array($sanitized)) {
            $cause = new \LogicException($useCase::name() . ' has an input schema that is not an object.');
            $error = new SystemFailure(null, $cause);
            throw $error;
        }

        /** @var array<string, mixed> $sanitized */
        return $sanitized;
    }

    /**
     * The subjects the checked input declares: the event of an admin use case that has one, and
     * the registrations it acts on (decision D4).
     *
     * @param array<string, mixed> $input
     * @return list<Subject>
     */
    private static function declaredSubjects(UseCase $useCase, array $input): array
    {
        $subjects = [];
        if ($useCase instanceof AdminUseCase) {
            $event = $useCase->event($input);
            if ($event !== null) {
                $subjects[] = Subject::event($event);
            }
            foreach ($useCase->targets($input) as $key) {
                $subjects[] = Subject::registration($key);
            }
        } elseif ($useCase instanceof PortalUseCase) {
            foreach ($useCase->registrations($input) as $key) {
                $subjects[] = Subject::registration($key);
            }
        }

        return $subjects;
    }

    /**
     * The reason, only when the use case's schema declares a string `reason` property (decision D8).
     *
     * @param array<string, mixed> $input the sanitized input
     */
    private static function reasonOf(UseCase $useCase, array $input): ?string
    {
        $properties = $useCase->inputSchema()['properties'] ?? null;
        $schema = is_array($properties) ? ($properties['reason'] ?? null) : null;
        $declared = is_array($schema) && ($schema['type'] ?? null) === 'string';
        $reason = $declared && is_string($input['reason'] ?? null) ? trim($input['reason']) : '';

        return $reason === '' ? null : $reason;
    }

    private static function asKalaka(\Throwable $error): KalakaException
    {
        return $error instanceof KalakaException ? $error : new SystemFailure(null, $error);
    }
}
