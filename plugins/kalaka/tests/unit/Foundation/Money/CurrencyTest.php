<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\Money;

use Kalaka\Foundation\Api\Money\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testHufIsStoredByItsIsoCode(): void
    {
        self::assertSame('HUF', Currency::HUF->value);
        self::assertSame(Currency::HUF, Currency::from('HUF'));
    }

    public function testHufMinorUnitIsOneFillerAndPayableUnitIsOneForint(): void
    {
        self::assertSame(2, Currency::HUF->minorUnitDecimals());
        self::assertSame(0, Currency::HUF->payableUnitDecimals());
    }

    public function testHufIsTheOnlyCurrency(): void
    {
        self::assertSame([Currency::HUF], Currency::cases());
    }
}
