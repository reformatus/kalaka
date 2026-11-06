<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Money;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use WP_UnitTestCase;

final class MoneyDecimalColumnTest extends WP_UnitTestCase
{
    /** @return iterable<string, array{int|string}> */
    public static function amounts(): iterable
    {
        yield 'int' => [48000];
        yield 'integer text' => ['48000'];
        yield 'negative fraction' => ['-0.5'];
        yield 'smallest unit' => ['0.00000001'];
        yield 'maximum' => ['999999999999999999.99999999'];
        yield 'minimum' => ['-999999999999999999.99999999'];
        yield 'stored form' => ['-0.50000000'];
    }

    /** @dataProvider amounts */
    public function testAmountRoundTripsThroughDecimalColumn(int|string $input): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kalaka_money_probe';
        $wpdb->query("CREATE TEMPORARY TABLE {$table} (amount DECIMAL(26,8) NOT NULL)");

        $original = Money::of($input, Currency::HUF);
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (amount) VALUES (%s)", $original->amount()));
        $read = $wpdb->get_var("SELECT amount FROM {$table}");

        $wpdb->query("DROP TEMPORARY TABLE {$table}");

        self::assertIsString($read);
        self::assertSame($original->amount(), $read);
        self::assertTrue(Money::of($read, Currency::HUF)->isEqualTo($original));
    }
}
