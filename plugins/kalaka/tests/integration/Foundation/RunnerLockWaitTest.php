<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\Statements;

/**
 * Lock waits: each attempt waits at most 5 seconds for a row lock, and the session's own timeout is in
 * force again after the run. The session starts at 20 seconds, so the runner's 5 shows; MariaDB checks
 * the wait about once a second, so a wait ends between 5 and about 7 seconds.
 */
final class RunnerLockWaitTest extends RealTableTestCase
{
    private Statements $statements;

    private int $originalTimeout = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->statements = new Statements();
        $wpdb->insert($this->table, ['label' => 'seed']);
        $wpdb->query('COMMIT');
        $this->originalTimeout = $this->statements->lockWaitTimeout();
        $this->statements->setLockWaitTimeout(20);
        $wpdb->query('START TRANSACTION');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        $this->statements->setLockWaitTimeout($this->originalTimeout);

        parent::tear_down();
    }

    public function testALockWaitTimeoutIsRetriedAndTheSecondAttemptSucceeds(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        $second->begin_transaction();
        $second->query("SELECT id FROM {$this->table} WHERE label = 'seed' FOR UPDATE");

        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($second): string {
            if ($call === 2) {
                $second->rollback();
            }
            try {
                $this->lockSeed();
            } catch (RetryableFailure $error) {
                self::assertSame(1, $call);
                self::assertStringContainsString('1205', (string) $error->getPrevious()?->getMessage());
                throw $error;
            }
            $this->statements->insert($this->table, ['label' => 'written']);

            return 'done';
        });

        $result = (new Runner(new TestClock(), true))->run($useCase, [], new SystemActor());

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertSame(
            ['seed', 'written'],
            array_map(
                'strval',
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
                $wpdb->get_col("SELECT label FROM {$this->table} ORDER BY id")
            )
        );
        self::assertSame(20, $this->statements->lockWaitTimeout());
    }

    public function testAFailedRestoreDoesNotPassTheRunsTimeoutOnToTheNextAttempt(): void
    {
        $failOnce = true;
        $breakRestore = static function (string $sql) use (&$failOnce): string {
            if ($failOnce && $sql === 'SET SESSION innodb_lock_wait_timeout = 20') {
                $failOnce = false;

                return 'SET SESSION innodb_lock_wait_timeout = \'not a number\'';
            }

            return $sql;
        };
        $log = (string) tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previousLog = (string) ini_get('error_log');
        ini_set('error_log', $log);
        add_filter('query', $breakRestore);
        try {
            $useCase = new AdminProbe(static function (array $input, Context $context, int $call): string {
                if ($call === 1) {
                    $error = new RetryableFailure();
                    throw $error;
                }

                return 'done';
            });
            $result = (new Runner(new TestClock(), true))->run($useCase, [], new SystemActor());
        } finally {
            remove_filter('query', $breakRestore);
            ini_set('error_log', $previousLog);
        }

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertFalse($failOnce);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's own log file
        $written = (string) file_get_contents($log);
        self::assertStringContainsString('Kaláka: restoring the lock-wait timeout failed, correlation ', $written);
        self::assertSame(20, $this->statements->lockWaitTimeout());
        wp_delete_file($log);
    }

    public function testEveryAttemptGivesUpAfterFiveSecondsAndTheRunEndsRetryableAfterFourAttempts(): void
    {
        $second = $this->secondConnection();
        $second->begin_transaction();
        $second->query("SELECT id FROM {$this->table} WHERE label = 'seed' FOR UPDATE");

        $timeouts = [];
        $waits = [];
        $useCase = new AdminProbe(function () use (&$timeouts, &$waits): string {
            $timeouts[] = $this->statements->lockWaitTimeout();
            $started = microtime(true);
            try {
                $this->lockSeed();
            } finally {
                $waits[] = microtime(true) - $started;
            }

            return 'done';
        });

        $caught = null;
        try {
            (new Runner(new TestClock(), true))->run($useCase, [], new SystemActor());
        } catch (RetryableFailure $error) {
            $caught = $error;
        }

        self::assertInstanceOf(RetryableFailure::class, $caught);
        self::assertStringContainsString('1205', (string) $caught->getPrevious()?->getMessage());
        self::assertSame(4, $useCase->calls);
        self::assertSame([5, 5, 5, 5], $timeouts);
        foreach ($waits as $wait) {
            self::assertGreaterThanOrEqual(4.5, $wait);
            self::assertLessThan(10.0, $wait);
        }
        self::assertSame(20, $this->statements->lockWaitTimeout());
    }

    private function lockSeed(): void
    {
        $this->statements->select(
            $this->statements->prepare('SELECT id FROM %i WHERE label = %s FOR UPDATE', [$this->table, 'seed'])
        );
    }
}
