<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;

final class RunnerLockWaitTest extends RealTableTestCase
{
    public function testALockWaitTimeoutIsRetriedAndTheSecondAttemptSucceeds(): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['label' => 'seed']);
        $wpdb->query('COMMIT');

        $second = $this->secondConnection();
        $second->begin_transaction();
        $second->query("SELECT id FROM {$this->table} WHERE label = 'seed' FOR UPDATE");

        $wpdb->suppress_errors(true);
        $originalTimeout = (string) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 1');
        $wpdb->query('START TRANSACTION');

        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($wpdb, $second): string {
            if ($call === 2) {
                $second->rollback();
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
            $wpdb->get_var("SELECT id FROM {$this->table} WHERE label = 'seed' FOR UPDATE");
            try {
                DatabaseError::check($wpdb);
            } catch (RetryableFailure $error) {
                self::assertSame(1, $call);
                self::assertStringContainsString('1205', (string) $error->getPrevious()?->getMessage());
                throw $error;
            }
            $wpdb->insert($this->table, ['label' => 'written']);
            DatabaseError::check($wpdb);

            return 'done';
        });

        try {
            $result = (new Runner(new TestClock(), true))->run($useCase, [], new SystemActor());
        } finally {
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalTimeout);
            $wpdb->suppress_errors(false);
        }

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
    }
}
