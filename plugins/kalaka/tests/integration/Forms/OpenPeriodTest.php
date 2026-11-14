<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventPoint;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\OpenPeriod;
use Kalaka\Foundation\Api\Money\Currency;
use WP_UnitTestCase;

/** S60: an additional form's open period, each end a date reference: a fixed date or an event point. */
final class OpenPeriodTest extends WP_UnitTestCase
{
    private static function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    private static function event(): Event
    {
        return new Event(
            7,
            1,
            'Teszt tábor',
            EventState::Published,
            Currency::HUF,
            41,
            false,
            self::utc('2026-11-30 08:00:00'),
            self::utc('2027-01-15 23:00:00'),
            self::utc('2027-07-05 08:00:00'),
            null,
            [new EventPoint(3, 'Modulválasztás vége', self::utc('2027-03-01 12:00:00'))]
        );
    }

    /** @param array<string, mixed>|string|null $opens */
    private static function form(array|string|null $opens, array|string|null $closes): Form
    {
        $json = static fn (array|string|null $end): ?string => is_array($end) ? (string) wp_json_encode($end) : $end;

        return new Form(5, 7, FormPlacement::Additional, null, false, 1, $json($opens), $json($closes));
    }

    public function testWithNeitherEndSetTheFormIsAlwaysOpen(): void
    {
        self::assertTrue(OpenPeriod::contains(self::form(null, null), self::event(), self::utc('2000-01-01 00:00:00')));
        self::assertSame(
            ['opens' => null, 'closes' => null],
            OpenPeriod::window(self::form(null, null), self::event())
        );
    }

    public function testAFixedDateOpensItAndAnotherClosesItAtThatInstant(): void
    {
        $form = self::form(
            ['kind' => 'fixed', 'at' => '2026-12-01 08:00:00'],
            ['kind' => 'fixed', 'at' => '2026-12-10 08:00:00']
        );

        self::assertFalse(OpenPeriod::contains($form, self::event(), self::utc('2026-12-01 07:59:59')));
        self::assertTrue(OpenPeriod::contains($form, self::event(), self::utc('2026-12-01 08:00:00')));
        self::assertTrue(OpenPeriod::contains($form, self::event(), self::utc('2026-12-10 07:59:59')));
        self::assertFalse(OpenPeriod::contains($form, self::event(), self::utc('2026-12-10 08:00:00')));
    }

    public function testAnEndCanBeACoreOrACustomEventPoint(): void
    {
        $form = self::form(
            ['kind' => 'point', 'point' => 'registration_opens'],
            ['kind' => 'point', 'point' => 'custom', 'id' => 3]
        );

        self::assertFalse(OpenPeriod::contains($form, self::event(), self::utc('2026-11-30 07:00:00')));
        self::assertTrue(OpenPeriod::contains($form, self::event(), self::utc('2027-02-01 00:00:00')));
        self::assertFalse(OpenPeriod::contains($form, self::event(), self::utc('2027-03-01 12:00:00')));
        self::assertEquals(
            ['opens' => self::utc('2026-11-30 08:00:00'), 'closes' => self::utc('2027-03-01 12:00:00')],
            OpenPeriod::window($form, self::event())
        );
    }

    public function testAnEndThatDoesNotResolveClosesTheForm(): void
    {
        $now = self::utc('2027-02-01 00:00:00');
        foreach (
            [
                'nem json',
                ['kind' => 'date_type', 'type' => 'payment_due'],
                ['kind' => 'fixed', 'at' => '2027-02-30 00:00:00'],
                ['kind' => 'fixed', 'at' => '2027-02-01'],
                ['kind' => 'point', 'point' => 'end'],
                ['kind' => 'point', 'point' => 'custom', 'id' => 99],
                ['kind' => 'point', 'point' => 'nyitas'],
            ] as $broken
        ) {
            self::assertFalse(
                OpenPeriod::contains(self::form(null, $broken), self::event(), $now),
                (string) wp_json_encode($broken)
            );
        }
    }
}
