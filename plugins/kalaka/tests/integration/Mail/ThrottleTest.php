<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Mail\Throttle;

/** The run's share of the hourly budget: `ceil(L / S) - C`. */
final class ThrottleTest extends MailTestCase
{
    private function claimedAt(string ...$times): void
    {
        global $wpdb;
        foreach ($times as $number => $time) {
            $wpdb->insert($wpdb->prefix . 'kalaka_deliveries', [
                'event_id' => 5, 'mail' => 'registered', 'code' => '00A' . $number, 'cause_type' => 'registration',
                'cause_key' => 'c1', 'version' => 1, 'kind' => 'action', 'status' => 'sent', 'attempts' => 1,
                'queued_at' => '2026-11-30 08:00:00.000000', 'claimed_at' => $time,
            ]);
        }
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testTheFirstSlotOfAnEmptyHourGetsATwelfth(): void
    {
        self::assertSame(20, (new Throttle())->share(self::utc('2026-11-30 09:00:30'), 240));
        self::assertSame(1, (new Throttle())->share(self::utc('2026-11-30 09:00:30'), 7));
    }

    public function testWhatIsLeftIsSharedByTheSlotsLeftLessThisSlotsClaims(): void
    {
        // Two claims in the previous hour do not count; three before this slot and one in it do.
        $this->claimedAt(
            '2026-11-30 08:59:59.000000',
            '2026-11-30 08:58:00.000000',
            '2026-11-30 09:00:01.000000',
            '2026-11-30 09:12:00.000000',
            '2026-11-30 09:14:59.999999',
            '2026-11-30 09:15:00.000000',
        );

        // 09:15 is the fourth slot: 9 slots left, 24 - 3 = 21 left, ceil(21 / 9) = 3, less 1.
        self::assertSame(2, (new Throttle())->share(self::utc('2026-11-30 09:16:00'), 24));
    }

    public function testTheShareIsZeroOrLessOnceTheSlotIsUsedUp(): void
    {
        $this->claimedAt('2026-11-30 09:00:01.000000', '2026-11-30 09:00:02.000000');

        self::assertSame(-1, (new Throttle())->share(self::utc('2026-11-30 09:00:30'), 12));
    }

    public function testTheNextSlotStartsAtTheNextFiveMinutesInUtc(): void
    {
        self::assertEquals(
            self::utc('2026-11-30 10:00:00'),
            Throttle::nextSlotStart(new \DateTimeImmutable('2026-11-30 10:58:00', new \DateTimeZone('Europe/Budapest')))
        );
    }
}
