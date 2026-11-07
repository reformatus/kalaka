<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

final class DatabaseErrorTest extends TestCase
{
    /** @return iterable<string, array{int, bool, bool}> error number, reference-code insert, retryable */
    public static function mappings(): iterable
    {
        yield 'deadlock' => [1213, false, true];
        yield 'lock wait timeout' => [1205, false, true];
        yield 'record has changed' => [1020, false, true];
        yield 'deadlock on a reference-code insert' => [1213, true, true];
        yield 'duplicate key' => [1062, false, false];
        yield 'duplicate key on a reference-code insert' => [1062, true, true];
        yield 'syntax error' => [1064, false, false];
        yield 'connection lost during a query: wpdb does not reconnect' => [2013, false, false];
        yield 'no number' => [0, true, false];
    }

    /** @dataProvider mappings */
    public function testMapping(int $number, bool $referenceCodeInsert, bool $retryable): void
    {
        $error = DatabaseError::exceptionFor($number, 'MariaDB says no', $referenceCodeInsert);

        self::assertSame($retryable ? RetryableFailure::class : SystemFailure::class, $error::class);
        self::assertTrue($error->isRetryable() === $retryable);
        self::assertSame('Váratlan hiba történt. Próbáld újra később.', $error->getMessage());
        self::assertStringContainsString((string) $number, (string) $error->getPrevious()?->getMessage());
        self::assertStringContainsString('MariaDB says no', (string) $error->getPrevious()?->getMessage());
    }
}
