<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Portal;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\MoneyText;
use PHPUnit\Framework\TestCase;

/** The amounts of the participant blocks: "48 000 Ft", no-break spaces, fillérs only when there are any. */
final class MoneyTextTest extends TestCase
{
    private static function text(string $amount): string
    {
        return str_replace("\u{00A0}", ' ', MoneyText::of(Money::of($amount, Currency::HUF)));
    }

    public function testThousandsAreGroupedAndTheCurrencyFollows(): void
    {
        self::assertSame('48 000 Ft', self::text('48000'));
        self::assertSame('0 Ft', self::text('0'));
        self::assertSame('999 Ft', self::text('999'));
        self::assertSame('1 000 000 Ft', self::text('1000000'));
        self::assertSame('1 234,50 Ft', self::text('1234.5'));
        self::assertSame('1 234,05 Ft', self::text('1234.059'));
        self::assertSame("\u{2212}2 000 Ft", self::text('-2000'));
        self::assertSame('0 Ft', self::text('-0.001'));
        self::assertSame("48\u{00A0}000\u{00A0}Ft", MoneyText::of(Money::of('48000', Currency::HUF)));
    }
}
