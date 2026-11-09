<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\ErrorLog;
use PHPUnit\Framework\TestCase;

final class ErrorLogChainTest extends TestCase
{
    public function testTheChainNamesEveryLinkWithItsClassCodeAndMessage(): void
    {
        $error = new \LogicException('outer', 7, new \RuntimeException('Database error 1213: Deadlock found', 1213));

        self::assertSame(
            'LogicException (7): outer <- RuntimeException (1213): Database error 1213: Deadlock found',
            ErrorLog::chain($error),
        );
    }

    public function testALineIsKeptWhenItsTextIsNotValidUtf8(): void
    {
        self::assertSame("Kaláka: a \xC3 b c", ErrorLog::oneLine("Kaláka: a \xC3\r\n  b\n\tc  "));
    }
}
