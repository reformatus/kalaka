<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\Side;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;

/**
 * Runs use cases: one transaction each, rollback on any error, retries, nested runs.
 * foundation.md, "The use-case runner".
 */
final class Runner
{
    /** The first attempt and three retries. */
    private const MAX_ATTEMPTS = 4;

    private static ?Runner $current = null;

    /** The attempt in progress, in any runner of this request. */
    private static ?RunState $active = null;

    private readonly \wpdb $wpdb;

    private readonly CoarsePermission $permission;

    /**
     * @param bool $insideOpenTransaction tests only: the harness already holds a transaction, so the
     *                                    outermost run uses a savepoint instead of its own transaction
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly bool $insideOpenTransaction = false,
    ) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->permission = new CoarsePermission();
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

        try {
            $side = self::sideOf($useCase);
            $input = self::checkedInput($useCase, $input);
            $this->permission->check($useCase, $side, $input, $actor);
            [$result, $steps] = $this->runWithRetries($useCase, $input, $actor);
        } catch (\Throwable $e) {
            $error = self::asKalaka($e);
            throw $error;
        }

        foreach ($steps as [$name, $step]) {
            try {
                $step();
            } catch (\Throwable $e) {
                error_log('Kaláka: after-commit step of ' . $name . ' failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{mixed, list<array{string, callable(): void}>}
     */
    private function runWithRetries(UseCase $useCase, array $input, Actor $actor): array
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $this->attempt($useCase, $input, $actor);
            } catch (KalakaException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || !self::isRetried($e, $actor)) {
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
     * One attempt from scratch: begin, execute, commit; an explicit rollback on any exception.
     *
     * @param array<string, mixed> $input
     * @return array{mixed, list<array{string, callable(): void}>}
     */
    private function attempt(UseCase $useCase, array $input, Actor $actor): array
    {
        $state = new RunState();
        self::$active = $state;
        $begun = false;

        try {
            $now = $this->clock->now();
            $begun = true;
            $this->statement($this->insideOpenTransaction ? 'SAVEPOINT kalaka_run' : 'START TRANSACTION');
            $result = $useCase->execute($input, $this->context($useCase, $actor, $now, $state));
            if ($state->failure !== null) {
                throw $state->failure;
            }
            $this->statement($this->insideOpenTransaction ? 'RELEASE SAVEPOINT kalaka_run' : 'COMMIT');
        } catch (\Throwable $e) {
            if ($begun) {
                $this->rollback();
            }
            $error = $state->failure ?? self::asKalaka($e);
            throw $error;
        } finally {
            $state->open = false;
            self::$active = null;
        }

        return [$result, $state->steps];
    }

    private function statement(string $sql): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- the runner owns the transaction; the statements are constants
        $this->wpdb->query($sql);
        DatabaseError::check($this->wpdb);
    }

    /** A failing rollback never replaces the original exception; PHP's error log is the last fallback. */
    private function rollback(): void
    {
        try {
            $this->statement($this->insideOpenTransaction ? 'ROLLBACK TO SAVEPOINT kalaka_run' : 'ROLLBACK');
        } catch (\Throwable $e) {
            error_log('Kaláka: rollback failed: ' . ($e->getPrevious() ?? $e)->getMessage());
        }
    }

    private function context(UseCase $useCase, Actor $actor, \DateTimeImmutable $now, RunState $state): Context
    {
        return new Context(
            $actor,
            $now,
            fn (UseCase $nested, array $input): mixed => $this->runNested($nested, $input, $actor, $now, $state),
            static function (callable $step) use ($useCase, $state): void {
                self::assertOpen($state);
                $state->steps[] = [$useCase::name(), $step];
            },
            static function (LockLevel $level) use ($state): void {
                self::assertOpen($state);
                if ($state->highestLock !== null && $level->value < $state->highestLock->value) {
                    $cause = new \LogicException(
                        'Lock order violated: ' . $level->name . ' requested after ' . $state->highestLock->name . '.'
                    );
                    $error = new SystemFailure(null, $cause);
                    throw $error;
                }
                $state->highestLock = $level;
            },
        );
    }

    /**
     * A nested run joins the transaction, uses the parent's actor and skips the coarse check.
     * Its first error ends the whole use case, even if a parent catches it; an InvalidInput
     * becomes a SystemFailure.
     *
     * @param array<string, mixed> $input
     */
    private function runNested(
        UseCase $useCase,
        array $input,
        Actor $actor,
        \DateTimeImmutable $now,
        RunState $state,
    ): mixed {
        try {
            self::assertOpen($state);
            self::sideOf($useCase);
            $input = self::checkedInput($useCase, $input);

            return $useCase->execute($input, $this->context($useCase, $actor, $now, $state));
        } catch (\Throwable $e) {
            $error = self::asKalaka($e);
            if ($error instanceof InvalidInput) {
                // The parent built this input, so a mismatch is a programming error.
                $error = new SystemFailure(null, $error);
            }
            $state->failure ??= $error;
            throw $error;
        }
    }

    private static function assertOpen(RunState $state): void
    {
        if (!$state->open) {
            $cause = new \LogicException('The context is used after its run has ended.');
            $error = new SystemFailure(null, $cause);
            throw $error;
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

    private static function asKalaka(\Throwable $error): KalakaException
    {
        return $error instanceof KalakaException ? $error : new SystemFailure(null, $error);
    }
}
