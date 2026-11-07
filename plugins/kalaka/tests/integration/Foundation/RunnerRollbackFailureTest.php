<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\Statements;

/**
 * AC1 of #42: a use case fails and so does its rollback, while the connection is still alive. A `query`
 * filter turns the runner's rollback statement into invalid SQL. The production runner then closes the
 * connection, which discards the transaction, and connects again before it writes the failed entry; in
 * the harness's savepoint mode it writes no entry.
 */
final class RunnerRollbackFailureTest extends RealTableTestCase
{
    private Statements $statements;

    /** The rollback statement the filter breaks, once; null: none. */
    private ?string $broken = null;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->statements = new Statements();
        add_filter('query', [$this, 'breakRollback']);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        remove_filter('query', [$this, 'breakRollback']);

        parent::tear_down();
    }

    /** The filter: the armed rollback statement becomes invalid SQL, so it fails on a live connection. */
    public function breakRollback(string $query): string
    {
        if ($this->broken !== null && $query === $this->broken) {
            $this->broken = null;

            return 'ROLLBACK BROKEN';
        }

        return $query;
    }

    public function testAFailedRollbackReconnectsSoNothingIsCommittedAndTheFailedEntryIsWritten(): void
    {
        global $wpdb;
        $threadBefore = $this->threadId();
        $this->broken = 'ROLLBACK';

        $caught = null;
        $log = $this->capturedErrorLog(function () use (&$caught): void {
            $caught = $this->failingRun(new Runner(new TestClock()));
        });

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame('use case failed', $caught->getPrevious()?->getMessage());
        self::assertNotSame($threadBefore, $this->threadId());
        self::assertSame([], $this->rowsSeenBy($this->secondConnection()));
        self::assertCount(1, $this->failedEntries());
        self::assertStringContainsString('Kaláka: rollback failed: ', $log);
        self::assertStringNotContainsString('audit entry not written', $log);
        self::assertSame('1', $wpdb->get_var('SELECT 1'));
    }

    public function testWhenTheReconnectFailsTooNothingIsCommittedAndOnlyTheErrorLogKeepsTheFailure(): void
    {
        global $wpdb;
        $this->broken = 'ROLLBACK';
        $host = $wpdb->dbhost;

        $caught = null;
        try {
            $log = $this->capturedErrorLog(function () use ($wpdb, &$caught): void {
                // The server stays unreachable for the reconnect: nothing listens on port 1.
                $wpdb->__set('dbhost', '127.0.0.1:1');
                // wpdb reports the failed connect as a PHP warning under WP_DEBUG; production hides it.
                set_error_handler(static fn (int $level): bool => $level === E_WARNING);
                try {
                    $caught = $this->failingRun(new Runner(new TestClock()));
                } finally {
                    restore_error_handler();
                }
            });
        } finally {
            $wpdb->__set('dbhost', $host);
            $wpdb->db_connect(false);
        }

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame('use case failed', $caught->getPrevious()?->getMessage());
        self::assertSame([], $this->rowsSeenBy($this->secondConnection()));
        self::assertSame([], $this->failedEntries());
        self::assertStringContainsString('Kaláka: rollback failed: ', $log);
        self::assertStringContainsString(
            'Kaláka: audit entry not written: kalaka/probe failed with kalaka_system_failure, correlation '
            . Correlation::id() . ': ' . SystemFailure::class,
            $log
        );
    }

    public function testAFailedRollbackToTheSavepointWritesNoFailedEntryAndLogsTheFallbackLine(): void
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $this->broken = 'ROLLBACK TO SAVEPOINT kalaka_run';

        $caught = null;
        $log = $this->capturedErrorLog(function () use (&$caught): void {
            $caught = $this->failingRun(new Runner(new TestClock(), true));
        });
        $failed = $this->failedEntries();
        $wpdb->query('ROLLBACK');

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame('use case failed', $caught->getPrevious()?->getMessage());
        self::assertSame([], $failed);
        self::assertStringContainsString('Kaláka: rollback failed: ', $log);
        self::assertStringContainsString(
            'Kaláka: audit entry not written: kalaka/probe failed with kalaka_system_failure, correlation '
            . Correlation::id() . ': ' . SystemFailure::class,
            $log
        );
        self::assertSame([], $this->rowsSeenBy($this->secondConnection()));
    }

    public function testAFailedRollbackToTheSavepointEndsTheRunWithoutARetry(): void
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $this->broken = 'ROLLBACK TO SAVEPOINT kalaka_run';
        $useCase = new AdminProbe(function (): never {
            $this->statements->insert($this->table, ['label' => 'doomed']);
            $error = new RetryableFailure();
            throw $error;
        });

        $caught = null;
        $this->capturedErrorLog(function () use ($useCase, &$caught): void {
            try {
                (new Runner(new TestClock(), true))->run($useCase, [], new SystemActor());
            } catch (KalakaException $error) {
                $caught = $error;
            }
        });
        $failed = $this->failedEntries();
        $wpdb->query('ROLLBACK');

        self::assertInstanceOf(RetryableFailure::class, $caught);
        self::assertSame(1, $useCase->calls);
        self::assertSame([], $failed);
    }

    /** Runs a use case that writes a row and then fails; returns what the runner threw. */
    private function failingRun(Runner $runner): ?KalakaException
    {
        $useCase = new AdminProbe(function (): never {
            $this->statements->insert($this->table, ['label' => 'doomed']);
            throw new \RuntimeException('use case failed');
        });
        try {
            $runner->run($useCase, [], new SystemActor());
        } catch (KalakaException $error) {
            self::assertSame(1, $useCase->calls);

            return $error;
        }

        return null;
    }

    private function threadId(): ?int
    {
        global $wpdb;
        $connection = $wpdb->__get('dbh');

        return $connection instanceof \mysqli ? $connection->thread_id : null;
    }

    /** @return list<?string> the error column of each failed entry this request wrote */
    private function failedEntries(): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT error FROM {$wpdb->prefix}kalaka_audit_entries WHERE correlation_id = %s AND outcome = 'failed'",
            Correlation::id()
        ));
    }

    /** Captures what PHP writes to its error log while the callback runs. */
    private function capturedErrorLog(callable $callback): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previous = ini_set('error_log', $file);
        try {
            $callback();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink($file);

        return $log;
    }
}
