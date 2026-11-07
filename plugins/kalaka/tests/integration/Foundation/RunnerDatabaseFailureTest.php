<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A database error inside a run is the run's failure: a use case that catches it cannot write
 * again, and the error decides the outcome. The runner is the production one, with a transaction
 * of its own, so a real deadlock rolls back only what the runner began.
 */
final class RunnerDatabaseFailureTest extends RealTableTestCase
{
    private const ITEMS = 'kalaka_test_items_real';

    private TestItemRepository $items;

    private Runner $runner;

    private int $rowA = 0;

    private int $rowB = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $wpdb->query("DROP TABLE IF EXISTS {$this->itemsTable()}");
        TestItemRepository::create(self::ITEMS);
        $wpdb->insert($wpdb->prefix . self::ITEMS, ['label' => 'A', 'version' => 1]);
        $this->rowA = $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . self::ITEMS, ['label' => 'B', 'version' => 1]);
        $this->rowB = $wpdb->insert_id;
        $wpdb->query('COMMIT');

        $this->items = new TestItemRepository(self::ITEMS);
        $this->runner = new Runner(new TestClock());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        parent::tear_down();

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $wpdb->query("DROP TABLE IF EXISTS {$this->itemsTable()}");
    }

    public function testAWriteAfterACaughtDeadlockIsRefusedAndTheRunIsRetriedFromScratch(): void
    {
        $deadlock = null;
        $refused = null;
        $useCase = new AdminProbe(
            function (array $input, Context $context, int $call) use (&$deadlock, &$refused): string {
                if ($call === 1) {
                    $this->items->upsert(new TestItem('first'), $context);
                    $deadlock = $this->deadlock($context);
                    try {
                        $this->items->upsert(new TestItem('after'), $context);
                    } catch (KalakaException $error) {
                        $refused = $error;
                        throw $error;
                    }
                }
                $this->items->upsert(new TestItem('second'), $context);

                return 'done';
            }
        );

        $result = null;
        $statements = $this->statementsDuring(function () use ($useCase, &$result): void {
            $result = $this->runner->run($useCase, [], new SystemActor());
        });

        self::assertSame('done', $result);
        self::assertSame(2, $useCase->calls);
        self::assertInstanceOf(RetryableFailure::class, $deadlock);
        self::assertStringContainsString('1213', (string) $deadlock->getPrevious()?->getMessage());
        self::assertSame($deadlock, $refused);
        self::assertSame([], preg_grep("/'after'/", $statements), 'The refused insert reached the database.');
        self::assertSame(['A', 'B', 'second'], $this->itemLabels());
    }

    public function testADeadlockTheUseCaseCatchesStillFailsTheAttemptThatHitIt(): void
    {
        $deadlock = null;
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$deadlock): string {
            if ($call === 1) {
                $this->items->upsert(new TestItem('first'), $context);
                $deadlock = $this->deadlock($context);

                return 'carried on';
            }
            $this->items->upsert(new TestItem('second'), $context);

            return 'done';
        });

        self::assertSame('done', $this->runner->run($useCase, [], new SystemActor()));
        self::assertSame(2, $useCase->calls);
        self::assertInstanceOf(RetryableFailure::class, $deadlock);
        self::assertSame(['A', 'B', 'second'], $this->itemLabels());
    }

    public function testAWriteAfterACaughtDuplicateKeyIsRefusedAndTheRunFailsWithIt(): void
    {
        $duplicate = null;
        $refused = null;
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$duplicate, &$refused): string {
            $this->items->upsert(new TestItem('first'), $context);
            try {
                $this->items->upsert(new TestItem('copy', id: $this->rowA), $context);
            } catch (SystemFailure $error) {
                $duplicate = $error;
            }
            try {
                $this->items->upsert(new TestItem('after'), $context);
            } catch (KalakaException $error) {
                $refused = $error;
            }

            return 'carried on';
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame($duplicate, $caught);
        }
        self::assertSame($duplicate, $refused);
        self::assertStringContainsString('1062', (string) $duplicate->getPrevious()?->getMessage());
        self::assertSame(1, $useCase->calls);
        self::assertSame(['A', 'B'], $this->itemLabels());
    }

    public function testAfterADatabaseErrorTheContextRefusesEveryWriteLockAndNestedRunButStillReads(): void
    {
        $nested = new AdminProbe(function (): void {
        });
        $duplicate = null;
        $outcomes = [];
        $read = null;
        $useCase = new AdminProbe(
            function (array $input, Context $context) use ($nested, &$duplicate, &$outcomes, &$read): void {
                try {
                    $this->items->upsert(new TestItem('copy', id: $this->rowA), $context);
                } catch (SystemFailure $error) {
                    $duplicate = $error;
                }
                $subject = new Subject('test_item', null, '1');
                $calls = [
                    'lock' => fn () => $context->lock(LockLevel::Event),
                    'lockRow' => fn () => $context->lockRow(LockLevel::Event, 'kalaka_events', [1]),
                    'afterCommit' => fn () => $context->afterCommit(function (): void {
                    }),
                    'touch' => fn () => $context->touch($subject),
                    'change' => fn () => $context->change($subject, 'label', 'a', 'b'),
                    'markTouched' => fn () => $context->markTouched(new RegistrationKey(1, 'ABCD')),
                    'run' => fn () => $context->run($nested, []),
                    'forUpdate' => fn () => $this->items->findForUpdate($this->rowB, $context),
                    'delete' => fn () => $this->items->delete($this->items->byLabel('B')[0], $context),
                ];
                foreach ($calls as $name => $call) {
                    try {
                        $call();
                        $outcomes[$name] = 'allowed';
                    } catch (\Throwable $error) {
                        $outcomes[$name] = $error === $duplicate ? 'refused' : $error::class;
                    }
                }
                $read = [$this->items->find($this->rowA)?->label, count($this->items->byLabel('B'))];
            }
        );

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame($duplicate, $caught);
        }
        self::assertInstanceOf(SystemFailure::class, $duplicate);
        self::assertSame(
            array_fill_keys(
                ['lock', 'lockRow', 'afterCommit', 'touch', 'change', 'markTouched', 'run', 'forUpdate', 'delete'],
                'refused'
            ),
            $outcomes
        );
        self::assertSame(0, $nested->calls);
        self::assertSame(['A', 1], $read);
        self::assertSame(['A', 'B'], $this->itemLabels());
    }

    /**
     * Holds row A, lets the second connection hold B and wait for A, then asks for B: a deadlock. The
     * second connection has written more, so InnoDB picks this attempt as the victim and rolls it back.
     */
    private function deadlock(Context $context): ?RetryableFailure
    {
        $second = $this->secondConnection();
        $second->begin_transaction();
        for ($i = 0; $i < 20; $i++) {
            $second->query("INSERT INTO {$this->table} (label) VALUES ('weight')");
        }
        $items = $this->itemsTable();
        $second->query("SELECT id FROM {$items} WHERE id = {$this->rowB} FOR UPDATE");

        $this->items->findForUpdate($this->rowA, $context);
        $second->query("SELECT id FROM {$items} WHERE id = {$this->rowA} FOR UPDATE", MYSQLI_ASYNC);
        $this->waitForLockWait($second);

        $deadlock = null;
        try {
            $this->items->findForUpdate($this->rowB, $context);
        } catch (RetryableFailure $error) {
            $deadlock = $error;
        }

        // The victim's rollback lets the second connection's read through.
        $second->reap_async_query();
        $second->rollback();
        $second->close();
        $this->second = null;

        return $deadlock;
    }

    private function waitForLockWait(\mysqli $connection): void
    {
        global $wpdb;
        for ($i = 0; $i < 100; $i++) {
            $state = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT trx_state FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = %d',
                    $connection->thread_id
                )
            );
            if ($state === 'LOCK WAIT') {
                return;
            }
            usleep(20000);
        }
        self::fail('The second connection never waited for the lock.');
    }

    private function itemsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::ITEMS;
    }

    /** @return list<string> */
    private function itemLabels(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        return array_map('strval', $wpdb->get_col("SELECT label FROM {$this->itemsTable()} ORDER BY id"));
    }

    /** @return list<string> the statements the database receives while the work runs */
    private function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }
}
