<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Time\Instant;

/** The one conversion between instants, UTC storage text and site-zone input. */
final class InstantTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
    }

    public function testStorageRoundTripKeepsMicrosecondsInUtc(): void
    {
        $time = new \DateTimeImmutable('2026-11-30 09:00:00.123456', new \DateTimeZone('Europe/Budapest'));

        self::assertSame('2026-11-30 08:00:00.123456', Instant::toStorage($time));
        self::assertEquals($time, Instant::fromStorage('2026-11-30 08:00:00.123456'));
    }

    public function testALocalTimeIsReadInTheSiteZone(): void
    {
        $at = Instant::fromLocal('2026-11-30 08:00');

        self::assertNotNull($at);
        self::assertSame('2026-11-30 07:00:00.000000', Instant::toStorage($at));
    }

    public function testAnAmbiguousLocalTimeIsTheEarlierInstant(): void
    {
        $at = Instant::fromLocal('2026-10-25 02:30');

        self::assertNotNull($at);
        self::assertSame('2026-10-25 00:30:00.000000', Instant::toStorage($at));
    }

    /** @dataProvider invalidLocalTimes */
    public function testInvalidLocalTimesAreRefused(string $text): void
    {
        self::assertNull(Instant::fromLocal($text));
    }

    /** @return array<string, array{string}> */
    public static function invalidLocalTimes(): array
    {
        return [
            'day only' => ['2026-12-01'],
            'seconds' => ['2026-12-01 10:00:00'],
            'no such day' => ['2026-02-30 10:00'],
            'no such hour' => ['2026-12-01 25:00'],
            'skipped hour' => ['2026-03-29 02:30'],
        ];
    }

    public function testTheStartOfADayIsMidnightInTheSiteZone(): void
    {
        $at = Instant::startOfDay('2026-12-01');

        self::assertNotNull($at);
        self::assertSame('2026-11-30 23:00:00.000000', Instant::toStorage($at));
        self::assertNull(Instant::startOfDay('2026-02-30'));
        self::assertNull(Instant::startOfDay('2026-12-01 10:00'));
    }
}
