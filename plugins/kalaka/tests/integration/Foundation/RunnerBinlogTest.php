<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Database\Statements;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Binary logging with `binlog_format=STATEMENT`: the runner refuses every use case before its transaction
 * (AC4), error 1665 ends a run without a retry (AC5), and a check that cannot read the variables refuses
 * nothing (AC8). The answer is faked (`FakeBinlog`); the runner is in savepoint mode.
 */
final class RunnerBinlogTest extends AuditTestCase
{
    private const REFUSAL = 'Váratlan hiba történt. Próbáld újra később. A webtárhely adatbázis-beállítása '
        . '(binlog_format=STATEMENT) miatt a Kaláka most nem tud adatot menteni. Szólj a webhely üzemeltetőjének.';

    /** A runner with a check of its own, so nothing is cached from an earlier read. */
    private function runnerWithNewCheck(): Runner
    {
        return new Runner($this->clock, true, binlog: new BinlogCheck());
    }

    private function writingProbe(): AdminProbe
    {
        return new AdminProbe(function (array $input, Context $context): string {
            $this->insertRow('a');

            return 'ran';
        });
    }

    /** @return list<string> the statements sent while the callback runs */
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

    public function testEveryUseCaseIsRefusedBeforeItsTransactionAndTheRefusalIsAuditedOnce(): void
    {
        $fake = FakeBinlog::statementFormat();
        $runner = $this->runnerWithNewCheck();
        $useCase = $this->writingProbe();

        $caught = null;
        $statements = $this->statementsDuring(function () use ($runner, $useCase, &$caught): void {
            try {
                $runner->run($useCase, [], new SystemActor());
            } catch (SystemFailure $error) {
                $caught = $error;
            }
        });

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame(SystemFailure::class, $caught::class);
        self::assertSame(self::REFUSAL, $caught->getMessage());
        self::assertFalse($caught->isRetryable());
        self::assertSame(0, $useCase->calls);
        self::assertSame(1, $fake->reads);
        self::assertSame([], preg_grep('/kalaka_run|TRANSACTION/', $statements), 'A transaction was begun.');
        self::assertSame([], $this->rows());

        $entry = $this->onlyEntry();
        self::assertSame('failed', $entry['outcome']);
        $error = $this->decode($entry['error']);
        self::assertSame('kalaka_system_failure', $error['code']);
        self::assertSame(self::REFUSAL, $error['message']);

        // The answer is kept: a second use case is refused without a second read.
        try {
            $runner->run($this->writingProbe(), [], new SystemActor());
            self::fail('The second run should have been refused.');
        } catch (SystemFailure $error) {
            self::assertSame(self::REFUSAL, $error->getMessage());
        }
        self::assertSame(1, $fake->reads);
    }

    public function testTheCoarsePermissionCheckComesFirst(): void
    {
        $fake = FakeBinlog::statementFormat();
        $useCase = $this->writingProbe();

        $this->expectException(PermissionDenied::class);
        try {
            $this->runnerWithNewCheck()->run($useCase, [], new SecretLinkActor(new RegistrationKey(10, 'ABCD')));
        } finally {
            self::assertSame(0, $fake->reads);
            self::assertSame(0, $useCase->calls);
        }
    }

    /** @return iterable<string, array{\Closure(): FakeBinlog}> */
    public static function writesWork(): iterable
    {
        yield 'binary logging with MIXED' => [static fn (): FakeBinlog => FakeBinlog::answering('1', 'MIXED')];
        yield 'no binary logging' => [static fn (): FakeBinlog => FakeBinlog::answering('0', 'STATEMENT')];
        yield 'the variables cannot be read' => [static fn (): FakeBinlog => FakeBinlog::failing()];
        yield 'the read returns nothing' => [static fn (): FakeBinlog => FakeBinlog::empty()];
    }

    /**
     * @dataProvider writesWork
     * @param \Closure(): FakeBinlog $answer
     */
    public function testWhenTheCheckPassesUseCasesRunAsBefore(\Closure $answer): void
    {
        $fake = $answer();
        $useCase = $this->writingProbe();

        self::assertSame('ran', $this->runnerWithNewCheck()->run($useCase, [], new SystemActor()));
        self::assertSame(1, $useCase->calls);
        self::assertSame(1, $fake->reads);
        self::assertSame(['a'], $this->rows());
        self::assertSame('succeeded', $this->onlyEntry()['outcome']);
    }

    public function testError1665EndsTheRunAfterOneAttemptWithTheRefusalText(): void
    {
        $signal = static fn (string $query): string => str_starts_with($query, 'INSERT INTO')
            && str_contains($query, 'kalaka_test_rows')
            ? "SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1665, MESSAGE_TEXT = 'Cannot execute statement'"
            : $query;
        add_filter('query', $signal);
        $useCase = new AdminProbe(function (): string {
            (new Statements())->insert($this->table, ['label' => 'a']);

            return 'ran';
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $error) {
            self::assertSame(SystemFailure::class, $error::class);
            self::assertSame(self::REFUSAL, $error->getMessage());
            self::assertSame(1665, $error->getPrevious()?->getCode());
        } finally {
            remove_filter('query', $signal);
        }

        self::assertSame(1, $useCase->calls);
        self::assertSame([], $this->rows());
        $error = $this->decode($this->onlyEntry()['error']);
        self::assertSame(self::REFUSAL, $error['message']);
    }
}
