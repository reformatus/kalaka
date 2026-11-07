<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\Statements;

/**
 * Each attempt's transaction is READ COMMITTED, set for it alone (AC1, AC2). The runner is the production
 * one, with a transaction of its own. AC1's other transaction runs in a child process (`lock-holder.php`),
 * because this process waits for its lock: it commits only once this connection waits for the lock.
 */
final class RunnerIsolationTest extends RealTableTestCase
{
    private Statements $statements;

    private int $rowR = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $wpdb->insert($this->table, ['label' => 'R']);
        $this->rowR = $wpdb->insert_id;
        $wpdb->query('COMMIT');

        $this->statements = new Statements();
    }

    public function testAfterAWaitedLockAPlainReadSeesTheRowTheOtherTransactionCommitted(): void
    {
        $before = null;
        $after = null;
        $useCase = new AdminProbe(function () use (&$before, &$after): string {
            [$before, $after] = $this->readLockRead();

            return 'done';
        });

        $holder = $this->holdRowR('Y');
        $result = (new Runner(new TestClock()))->run($useCase, [], new SystemActor());

        self::assertSame("waited\n", $this->finish($holder), 'The lock was not waited for.');
        self::assertSame('done', $result);
        self::assertSame(['R'], $before);
        self::assertSame(['R', 'Y'], $after);
    }

    public function testUnderRepeatableReadTheSameStepsMissTheRow(): void
    {
        global $wpdb;
        $holder = $this->holdRowR('Y');
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $wpdb->query('START TRANSACTION');
        try {
            [$before, $after] = $this->readLockRead();
        } finally {
            $wpdb->query('ROLLBACK');
        }

        self::assertSame("waited\n", $this->finish($holder), 'The lock was not waited for.');
        self::assertSame(['R'], $before);
        self::assertSame(['R'], $after);
        self::assertSame(['R', 'Y'], $this->rowsSeenBy($this->secondConnection()));
    }

    public function testEveryAttemptRunsAtReadCommittedAndTheSessionKeepsItsOwnLevel(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        $sessionLevel = (string) $wpdb->get_var('SELECT @@SESSION.tx_isolation');
        $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $seen = [];
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($second, &$seen): string {
            $before = $this->labels();
            $second->query("INSERT INTO {$this->table} (label) VALUES ('committed meanwhile {$call}')");
            $seen[$call] = [$before, $this->labels()];
            if ($call === 1) {
                $error = new RetryableFailure();
                throw $error;
            }

            return 'done';
        });

        try {
            $statements = [];
            $capture = static function (string $query) use (&$statements): string {
                $statements[] = $query;

                return $query;
            };
            add_filter('query', $capture);
            try {
                $result = (new Runner(new TestClock()))->run($useCase, [], new SystemActor());
            } finally {
                remove_filter('query', $capture);
            }

            $level = (string) $wpdb->get_var('SELECT @@SESSION.tx_isolation');
            $wpdb->query('COMMIT');
            $this->statements->begin(false);
            $before = $this->labels();
            $second->query("INSERT INTO {$this->table} (label) VALUES ('committed after')");
            $afterwards = $this->labels();
            $this->statements->rollback();
        } finally {
            $wpdb->query($wpdb->prepare('SET SESSION tx_isolation = %s', $sessionLevel));
        }

        self::assertSame('done', $result);
        self::assertSame(
            [
                1 => [['R'], ['R', 'committed meanwhile 1']],
                2 => [['R', 'committed meanwhile 1'], ['R', 'committed meanwhile 1', 'committed meanwhile 2']],
            ],
            $seen
        );
        self::assertSame(
            [
                'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
                'START TRANSACTION',
                'ROLLBACK',
                'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
                'START TRANSACTION',
                'COMMIT',
            ],
            array_values(preg_grep('/^(SET TRANSACTION|START TRANSACTION|COMMIT|ROLLBACK)/', $statements) ?: [])
        );
        self::assertSame('REPEATABLE-READ', $level);
        // A new transaction on the connection is the session's own REPEATABLE READ again.
        self::assertSame($before, $afterwards);
    }

    /**
     * A plain read, `FOR UPDATE` on row R, a plain read: the steps that miss a row committed during the
     * wait when the first read fixed the snapshot.
     *
     * @return array{list<string>, list<string>}
     */
    private function readLockRead(): array
    {
        $before = $this->labels();
        $this->statements->select(
            $this->statements->prepare('SELECT id FROM %i WHERE id = %d FOR UPDATE', [$this->table, $this->rowR])
        );

        return [$before, $this->labels()];
    }

    /** @return list<string> the labels this connection sees, by a plain read */
    private function labels(): array
    {
        $rows = $this->statements->select(
            $this->statements->prepare('SELECT label FROM %i ORDER BY id', [$this->table])
        );

        return array_map(static fn (array $row): string => (string) $row['label'], $rows);
    }

    /**
     * Starts `lock-holder.php`, which locks row R, inserts a row with the label uncommitted and commits once
     * this connection waits for R; returns when R is held.
     *
     * @return array{resource, array<int, resource>}
     */
    private function holdRowR(string $label): array
    {
        global $wpdb;
        $connection = $wpdb->__get('dbh');
        self::assertInstanceOf(\mysqli::class, $connection);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/lock-holder.php', $this->table, (string) $this->rowR, $label,
                (string) $connection->thread_id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'KALAKA_DB_HOST' => DB_HOST,
                'KALAKA_DB_USER' => DB_USER,
                'KALAKA_DB_PASSWORD' => DB_PASSWORD,
                'KALAKA_DB_NAME' => DB_NAME,
            ]
        );
        self::assertIsResource($process);
        $line = fgets($pipes[1]);
        if ($line !== "locked\n") {
            // Only now: reading its errors waits for the script to end.
            self::fail('lock-holder.php: ' . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $holder
     * @return string what `lock-holder.php` printed after "locked"
     */
    private function finish(array $holder): string
    {
        [$process, $pipes] = $holder;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the pipe to the script
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'lock-holder.php: ' . $errors);

        return $output;
    }
}
