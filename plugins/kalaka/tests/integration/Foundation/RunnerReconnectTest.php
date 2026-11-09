<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\Statements;

/**
 * A reconnect inside a run fails the attempt as retryable. The test closes the connection and reconnects
 * the way wpdb does after errno 2006 (`db_connect(false)`, a new connection), from a `query` filter, just
 * before a chosen statement, which wpdb then sends on the new connection. The runner is the production one, with a
 * transaction of its own.
 */
final class RunnerReconnectTest extends RealTableTestCase
{
    private Runner $runner;

    private Statements $statements;

    /** Armed by the use case: the next matching statement is sent on a new connection. */
    private bool $armed = false;

    private int $reconnects = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->runner = new Runner(new TestClock());
        $this->statements = new Statements();
    }

    public function testAReconnectAtEveryCommitFailsTheRunAsRetryableAndKeepsNothing(): void
    {
        $steps = 0;
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$steps): string {
            $this->statements->insert($this->table, ['label' => 'written']);
            $context->afterCommit(static function () use (&$steps): void {
                $steps++;
            });
            $this->armed = true;

            return 'done';
        });

        $caught = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$caught): void {
            $this->reconnectingBefore(static fn (string $query): bool => $query === 'COMMIT', function () use (
                $useCase,
                &$caught,
            ): void {
                try {
                    $this->runner->run($useCase, [], new SystemActor());
                } catch (KalakaException $error) {
                    $caught = $error;
                }
            });
        });

        self::assertInstanceOf(RetryableFailure::class, $caught);
        self::assertSame(2006, $caught->getPrevious()?->getCode());
        self::assertSame(4, $useCase->calls);
        self::assertSame(4, $this->reconnects);
        self::assertSame(0, $steps);
        self::assertSame([], $this->rowsSeenBy($this->secondConnection()));

        $failed = $this->failedEntries();
        self::assertCount(1, $failed);
        $error = json_decode((string) $failed[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('kalaka_retryable_failure', $error['code']);
        self::assertSame(\RuntimeException::class, $error['previous'][0]['class']);
        self::assertSame(2006, $error['previous'][0]['code']);

        $line = 'Kaláka: reconnect inside kalaka/probe; the statement wpdb re-sent may have committed on its own,'
            . ' correlation ' . Correlation::id();
        self::assertSame(4, substr_count($log, $line));
        // The runner's own rollback, on the new connection, is not taken for a reconnect.
        self::assertStringNotContainsString('rollback failed', $log);
    }

    public function testAReconnectAtTheFirstCommitOnlyIsRetriedAndTheSecondAttemptSucceeds(): void
    {
        $steps = 0;
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$steps): string {
            $this->statements->insert($this->table, ['label' => 'written ' . $call]);
            $context->afterCommit(static function () use (&$steps): void {
                $steps++;
            });
            $this->armed = $call === 1;

            return 'done';
        });

        $result = null;
        $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $this->reconnectingBefore(static fn (string $query): bool => $query === 'COMMIT', function () use (
                $useCase,
                &$result,
            ): void {
                $result = $this->runner->run($useCase, [], new SystemActor());
            });
        });

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertSame(1, $this->reconnects);
        self::assertSame(1, $steps);
        self::assertSame(['written 2'], $this->rowsSeenBy($this->secondConnection()));
        self::assertSame([], $this->failedEntries());
    }

    public function testAReconnectAtTheBeginIsRolledBackRetriedAndLeavesNoTransactionOpen(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context, int $call): string {
            $this->statements->insert($this->table, ['label' => 'written ' . $call]);

            return 'done';
        });
        $this->armed = true;

        $result = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $this->reconnectingBefore(
                static fn (string $query): bool => $query === 'START TRANSACTION',
                function () use ($useCase, &$result): void {
                    $result = $this->runner->run($useCase, [], new SystemActor());
                }
            );
        });

        self::assertSame('done', $result);
        self::assertSame(1, $useCase->calls);
        self::assertSame(1, $this->reconnects);
        self::assertSame(1, substr_count($log, 'Kaláka: reconnect inside kalaka/probe; '));
        global $wpdb;
        self::assertSame('0', $wpdb->get_var('SELECT @@in_transaction'));
        $next = new AdminProbe(static fn (): string => 'again');
        self::assertSame('again', $this->runner->run($next, [], new SystemActor()));
        self::assertSame(['written 1'], $this->rowsSeenBy($this->secondConnection()));
    }

    public function testACommitTheServerMadeBeforeTheReconnectIsASuccessAndNotRetried(): void
    {
        $steps = 0;
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$steps): string {
            $this->statements->insert($this->table, ['label' => 'written ' . $call]);
            $context->afterCommit(static function () use (&$steps): void {
                $steps++;
            });
            $this->armed = $call === 1;

            return 'done';
        });

        $result = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $this->reconnectingBefore(
                static fn (string $query): bool => $query === 'COMMIT',
                function () use ($useCase, &$result): void {
                    $result = $this->runner->run($useCase, [], new SystemActor());
                },
                true
            );
        });

        self::assertSame('done', $result);
        self::assertSame(1, $useCase->calls);
        self::assertSame(1, $this->reconnects);
        self::assertSame(1, $steps);
        self::assertSame(['written 1'], $this->rowsSeenBy($this->secondConnection()));
        self::assertCount(1, $this->succeededEntries());
        self::assertSame([], $this->failedEntries());
        self::assertSame(1, substr_count($log, 'Kaláka: reconnect inside kalaka/probe; '));
    }

    public function testAReconnectBeforeAnInsertEndsTheAttemptRightAfterItAndTheRetrySucceeds(): void
    {
        $reached = [];
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$reached): string {
            $this->armed = $call === 1;
            $this->statements->insert($this->table, ['label' => 'first ' . $call]);
            $reached[] = $call;
            $this->statements->insert($this->table, ['label' => 'second ' . $call]);

            return 'done';
        });

        $result = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $this->reconnectingBefore(
                static fn (string $query): bool => str_starts_with($query, 'INSERT'),
                function () use ($useCase, &$result): void {
                    $result = $this->runner->run($useCase, [], new SystemActor());
                }
            );
        });

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertSame(1, $this->reconnects);
        self::assertSame([2], $reached);
        // The insert wpdb re-sent ran alone on the new connection and committed on its own.
        self::assertSame(['first 1', 'first 2', 'second 2'], $this->rowsSeenBy($this->secondConnection()));
        self::assertSame(1, substr_count($log, 'Kaláka: reconnect inside kalaka/probe; '));
    }

    public function testACaughtReconnectIsTheRunsFailureTheContextRefusesWritesAndTheRunIsRetried(): void
    {
        $reconnect = null;
        $refused = [];
        $useCase = new AdminProbe(
            function (array $input, Context $context, int $call) use (&$reconnect, &$refused): string {
                $this->armed = $call === 1;
                try {
                    $this->statements->insert($this->table, ['label' => 'first ' . $call]);
                } catch (RetryableFailure $error) {
                    $reconnect = $error;
                    foreach (
                        [
                            'lock' => fn () => $context->lock(LockLevel::Other),
                            'afterCommit' => fn () => $context->afterCommit(static function (): void {
                            }),
                        ] as $name => $write
                    ) {
                        try {
                            $write();
                        } catch (\Throwable $refusal) {
                            $refused[$name] = $refusal;
                        }
                    }

                    return 'carried on';
                }

                return 'done';
            }
        );

        $result = null;
        $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $this->reconnectingBefore(
                static fn (string $query): bool => str_starts_with($query, 'INSERT'),
                function () use ($useCase, &$result): void {
                    $result = $this->runner->run($useCase, [], new SystemActor());
                }
            );
        });

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertInstanceOf(RetryableFailure::class, $reconnect);
        self::assertSame(2006, $reconnect->getPrevious()?->getCode());
        self::assertSame(['lock' => $reconnect, 'afterCommit' => $reconnect], $refused);
        self::assertSame(['first 1', 'first 2'], $this->rowsSeenBy($this->secondConnection()));
    }

    /**
     * Runs the work with the reconnect filter: an armed, matching statement goes out on a new connection.
     * With `$sentFirst`, the old connection runs it first: the server did it, and only the reply was lost.
     */
    private function reconnectingBefore(\Closure $matches, \Closure $work, bool $sentFirst = false): void
    {
        global $wpdb;
        $reconnect = function (string $query) use ($wpdb, $matches, $sentFirst): string {
            if ($this->armed && $matches($query)) {
                $this->armed = false;
                $this->reconnects++;
                // The server dropped the old connection; wpdb opens a new one.
                $old = $wpdb->__get('dbh');
                if ($old instanceof \mysqli) {
                    if ($sentFirst) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the statement wpdb is about to send
                        $old->query($query);
                    }
                    $old->close();
                }
                $wpdb->db_connect(false);
            }

            return $query;
        };
        add_filter('query', $reconnect);
        try {
            $work();
        } finally {
            remove_filter('query', $reconnect);
            $this->armed = false;
        }
    }

    /** @return list<string> the ids of the succeeded entries this request wrote */
    private function succeededEntries(): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kalaka_audit_entries WHERE correlation_id = %s AND outcome = 'succeeded'",
            Correlation::id()
        ));
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
