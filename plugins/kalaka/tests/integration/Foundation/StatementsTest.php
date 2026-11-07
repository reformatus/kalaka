<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Database\Statements;

/**
 * Each statement of `Statements` against the test database: it takes effect, and a failing one
 * throws what `DatabaseError::check()` throws. A real table, so a second connection sees commits.
 */
final class StatementsTest extends RealTableTestCase
{
    private Statements $statements;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->statements = new Statements();
    }

    public function testPrefixedAddsTheWordPressPrefix(): void
    {
        global $wpdb;

        self::assertSame($wpdb->prefix . 'kalaka_test_real', $this->statements->prefixed('kalaka_test_real'));
    }

    public function testBeginAndCommitMakeTheRowsVisibleToAnotherConnection(): void
    {
        $this->statements->begin();
        $this->statements->insert($this->table, ['label' => 'Teszt Elek']);

        $second = $this->secondConnection();
        self::assertSame([], $this->rowsSeenBy($second));

        $this->statements->commit();

        self::assertSame(['Teszt Elek'], $this->rowsSeenBy($second));
    }

    public function testRollbackUndoesTheTransaction(): void
    {
        $this->statements->begin();
        $this->statements->insert($this->table, ['label' => 'Teszt Elek']);
        $this->statements->rollback();

        self::assertSame([], $this->labels());
    }

    public function testSavepointsRollBackAndReleaseTheirPart(): void
    {
        $this->statements->begin();
        $this->statements->insert($this->table, ['label' => 'before']);
        $this->statements->savepoint('kalaka_run');
        $this->statements->insert($this->table, ['label' => 'rolled back']);
        $this->statements->rollbackToSavepoint('kalaka_run');
        $this->statements->savepoint('kalaka_audit');
        $this->statements->insert($this->table, ['label' => 'released']);
        $this->statements->releaseSavepoint('kalaka_audit');
        $this->statements->commit();

        self::assertSame(['before', 'released'], $this->rowsSeenBy($this->secondConnection()));
    }

    public function testAnUnknownSavepointNameIsRefusedWithoutAStatement(): void
    {
        global $wpdb;
        $before = $wpdb->num_queries;

        foreach (['savepoint', 'releaseSavepoint', 'rollbackToSavepoint'] as $method) {
            try {
                $this->statements->{$method}('other');
                self::fail($method . '() should have thrown.');
            } catch (SystemFailure $caught) {
                self::assertInstanceOf(\LogicException::class, $caught->getPrevious());
            }
        }

        self::assertSame($before, $wpdb->num_queries);
    }

    public function testReleasingAMissingSavepointIsASystemFailure(): void
    {
        $this->statements->begin();

        $this->assertFailure(SystemFailure::class, '1305', fn () => $this->statements->releaseSavepoint('kalaka_run'));
    }

    public function testPrepareReturnsTheStatementAsItIsWithoutArgumentsAndPreparesWithThem(): void
    {
        self::assertSame('SELECT 1', $this->statements->prepare('SELECT 1', []));
        self::assertSame(
            "SELECT * FROM `{$this->table}` WHERE label = 'Teszt Elek'",
            $this->statements->prepare('SELECT * FROM %i WHERE label = %s', [$this->table, 'Teszt Elek'])
        );
    }

    public function testAPrepareThatFailsIsASystemFailure(): void
    {
        $this->setExpectedIncorrectUsage('wpdb::prepare');

        try {
            $this->statements->prepare('SELECT %d, %d', [1]);
            self::fail('prepare() should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertInstanceOf(\LogicException::class, $caught->getPrevious());
        }
    }

    public function testSelectReturnsEveryValueAsAStringOrNull(): void
    {
        $id = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);

        $rows = $this->statements->select($this->statements->prepare(
            'SELECT id, label, NULL AS nothing FROM %i ORDER BY id',
            [$this->table]
        ));

        self::assertSame([['id' => (string) $id, 'label' => 'Teszt Elek', 'nothing' => null]], $rows);
    }

    public function testAFailingSelectIsASystemFailure(): void
    {
        $this->assertFailure(
            SystemFailure::class,
            '1054',
            fn () => $this->statements->select($this->statements->prepare('SELECT missing FROM %i', [$this->table]))
        );
    }

    public function testALockWaitTimeoutIsRetryable(): void
    {
        global $wpdb;
        $this->statements->insert($this->table, ['label' => 'Teszt Elek']);
        $this->statements->commit();

        $second = $this->secondConnection();
        $second->begin_transaction();
        $second->query("SELECT id FROM {$this->table} FOR UPDATE");

        $wpdb->suppress_errors(true);
        $originalTimeout = (string) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 1');
        $wpdb->suppress_errors(false);
        try {
            $this->statements->begin();
            $this->assertFailure(
                RetryableFailure::class,
                '1205',
                fn () => $this->statements->select(
                    $this->statements->prepare('SELECT id FROM %i FOR UPDATE', [$this->table])
                )
            );
        } finally {
            $second->rollback();
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalTimeout);
        }
    }

    public function testInsertReturnsTheNewId(): void
    {
        $first = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);
        $second = $this->statements->insert($this->table, ['label' => 'Teszt Anna'], ['%s']);

        self::assertGreaterThan(0, $first);
        self::assertSame($first + 1, $second);
        self::assertSame(['Teszt Elek', 'Teszt Anna'], $this->labels());
    }

    public function testADuplicateInsertIsASystemFailureOrRetryableForAReferenceCode(): void
    {
        $id = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);

        $this->assertFailure(
            SystemFailure::class,
            '1062',
            fn () => $this->statements->insert($this->table, ['id' => $id, 'label' => 'Teszt Anna'])
        );
        $this->assertFailure(
            RetryableFailure::class,
            '1062',
            fn () => $this->statements->insert($this->table, ['id' => $id, 'label' => 'Teszt Anna'], null, true)
        );
    }

    public function testUpdateChangesTheRowsAndReturnsHowMany(): void
    {
        $id = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);

        self::assertSame(1, $this->statements->update($this->table, ['label' => 'Teszt Anna'], ['id' => $id]));
        self::assertSame(0, $this->statements->update($this->table, ['label' => 'Teszt Anna'], ['id' => $id + 1]));
        self::assertSame(['Teszt Anna'], $this->labels());
    }

    public function testAFailingUpdateIsASystemFailure(): void
    {
        $first = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);
        $second = $this->statements->insert($this->table, ['label' => 'Teszt Anna']);

        $this->assertFailure(
            SystemFailure::class,
            '1062',
            fn () => $this->statements->update($this->table, ['id' => $first], ['id' => $second])
        );
    }

    public function testDeleteRemovesTheRowsAndReturnsHowMany(): void
    {
        $id = $this->statements->insert($this->table, ['label' => 'Teszt Elek']);

        self::assertSame(0, $this->statements->delete($this->table, ['id' => $id + 1]));
        self::assertSame(1, $this->statements->delete($this->table, ['id' => $id]));
        self::assertSame([], $this->labels());
    }

    public function testAFailingDeleteIsASystemFailure(): void
    {
        $this->assertFailure(
            SystemFailure::class,
            '1146',
            fn () => $this->statements->delete($this->table . '_missing', ['id' => 1])
        );
    }

    public function testDropTableIfExistsDropsTheTable(): void
    {
        global $wpdb;

        $this->statements->dropTableIfExists($this->table);
        $this->statements->dropTableIfExists($this->table);

        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table)));
    }

    public function testAFailingDropIsASystemFailure(): void
    {
        $this->assertFailure(
            SystemFailure::class,
            '1103',
            fn () => $this->statements->dropTableIfExists(str_repeat('x', 65))
        );
    }

    /** @return list<string> the labels this connection sees */
    private function labels(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        return array_map('strval', $wpdb->get_col("SELECT label FROM {$this->table} ORDER BY id"));
    }

    /**
     * @param class-string<SystemFailure|RetryableFailure> $class
     * @param \Closure(): mixed $statement
     */
    private function assertFailure(string $class, string $number, \Closure $statement): void
    {
        try {
            $statement();
            self::fail('The statement should have failed.');
        } catch (SystemFailure | RetryableFailure $caught) {
            self::assertSame($class, $caught::class);
            self::assertStringContainsString($number, (string) $caught->getPrevious()?->getMessage());
        }
    }
}
