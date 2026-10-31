<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\ApiVersionRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiVersionRangeTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'lower bound met' => ['>=0.1', '0.1.0', true];
        yield 'lower bound missed' => ['>=0.2', '0.1.9', false];
        yield 'inside a range' => ['>=1.4 <2.0', '1.9.3', true];
        yield 'upper bound excluded' => ['>=1.4 <2.0', '2.0.0', false];
        yield 'core bound on a prerelease' => ['>=0.2.0-rc.1', '0.2.0-rc.1', true];
        yield 'release after its prerelease' => ['>=0.2.0-rc.1', '0.2.0', true];
        yield 'prerelease before its release' => ['>=0.2.0', '0.2.0-rc.1', false];
        yield 'suffix dots do not count as patch' => ['>=0.2-rc.1', '0.2.0-rc.1', true];
    }

    #[DataProvider('cases')]
    public function testAllows(string $range, string $version, bool $expected): void
    {
        self::assertSame($expected, ApiVersionRange::parse($range)->allows($version));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown operator' => ['>0.1'];
        yield 'three parts' => ['>=0.1 <0.2 <0.3'];
        yield 'not a version' => ['>=abc'];
    }

    #[DataProvider('invalid')]
    public function testRejects(string $range): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiVersionRange::parse($range);
    }
}
