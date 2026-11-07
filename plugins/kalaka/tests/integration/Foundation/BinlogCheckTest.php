<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Database\BinlogCheck;
use WP_UnitTestCase;

/**
 * `BinlogCheck` refuses only while binary logging is on with `binlog_format=STATEMENT`, reads once per
 * instance, and counts a read that fails or returns nothing as "writes work" (AC8, D5).
 */
final class BinlogCheckTest extends WP_UnitTestCase
{
    public function testTheTestServerLogsNoBinaryAndWritesWork(): void
    {
        self::assertFalse((new BinlogCheck())->refuses());
    }

    /** @return iterable<string, array{string, string, bool}> log_bin, binlog_format, refuses */
    public static function answers(): iterable
    {
        yield 'on, STATEMENT' => ['1', 'STATEMENT', true];
        yield 'ON in words, statement in lower case' => ['ON', 'statement', true];
        yield 'on, MIXED' => ['1', 'MIXED', false];
        yield 'on, ROW' => ['1', 'ROW', false];
        yield 'off, STATEMENT' => ['0', 'STATEMENT', false];
        yield 'OFF in words, STATEMENT' => ['OFF', 'STATEMENT', false];
    }

    /** @dataProvider answers */
    public function testItRefusesOnlyWhileBinaryLoggingRunsWithTheStatementFormat(
        string $logBin,
        string $format,
        bool $refuses,
    ): void {
        FakeBinlog::answering($logBin, $format);

        self::assertSame($refuses, (new BinlogCheck())->refuses());
    }

    public function testItReadsOnceAndKeepsTheAnswer(): void
    {
        $fake = FakeBinlog::statementFormat();
        $check = new BinlogCheck();

        self::assertTrue($check->refuses());
        $fake->remove();
        self::assertTrue($check->refuses());
        self::assertSame(1, $fake->reads);
    }

    public function testAReadThatFailsOrReturnsNothingCountsAsWritesWork(): void
    {
        foreach ([FakeBinlog::failing(), FakeBinlog::empty()] as $fake) {
            $check = new BinlogCheck();
            self::assertFalse($check->refuses());
            self::assertFalse($check->refuses());
            self::assertSame(1, $fake->reads);
            $fake->remove();
        }
    }
}
