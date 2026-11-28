<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Money\Currency;

/** Payment due (money.md, "Core money scope"): counted from the later of the last registration and the last answer. */
final class PaymentDueTest extends EventsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
    }

    private function event(?int $days = 7): Event
    {
        return new Event(
            7,
            1,
            'Teszt tábor',
            EventState::Published,
            Currency::HUF,
            null,
            false,
            null,
            null,
            null,
            null,
            [],
            paymentDueDays: $days,
        );
    }

    private function utc(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function due(?\DateTimeImmutable $due): ?string
    {
        return $due?->format('Y-m-d H:i:s');
    }

    public function testDueCountsFromWhenTheRegistrationLastBecameRegistered(): void
    {
        $event = $this->event();

        self::assertSame('2026-11-27 23:00:00', $this->due($event->paymentDue($this->utc('2026-11-20 10:00:00'))));
        self::assertSame(
            '2026-11-27 23:00:00',
            $this->due($event->paymentDue($this->utc('2026-11-20 10:00:00'), null, false))
        );
    }

    public function testALaterAnswerMovesItAndAnEarlierOneChangesNothing(): void
    {
        $event = $this->event();
        $registered = $this->utc('2026-11-20 10:00:00');

        self::assertSame(
            '2026-12-07 23:00:00',
            $this->due($event->paymentDue($registered, $this->utc('2026-11-30 12:00:00')))
        );
        self::assertSame(
            '2026-11-27 23:00:00',
            $this->due($event->paymentDue($registered, $this->utc('2026-11-10 12:00:00'))),
            'an answer given before the registration became registered (while waitlisted) counts from the admission'
        );
    }

    public function testAnAnswerJustBeforeLocalMidnightInUtcCountsAsTheNextBudapestDay(): void
    {
        // 23:30 UTC on 30 Nov is 00:30 on 1 Dec in Budapest: the day is 1 Dec, and 7 days later end at 9 Dec 00:00
        // local (a UTC cut would give 8 Dec 00:00 local, 7 Dec 23:00 UTC).
        self::assertSame(
            '2026-12-08 23:00:00',
            $this->due($this->event()->paymentDue(
                $this->utc('2026-11-20 10:00:00'),
                $this->utc('2026-11-30 23:30:00')
            ))
        );
    }

    public function testWhileARequestAwaitsAnAnswerThereIsNoDueDate(): void
    {
        $event = $this->event();

        $registered = $this->utc('2026-11-20 10:00:00');

        self::assertNull($event->paymentDue($registered, $this->utc('2026-11-25 10:00:00'), true));
        self::assertNull($event->paymentDue($registered, null, true));
    }

    public function testNoDueWithoutNOrWithoutARegisteredInstant(): void
    {
        self::assertNull($this->event(null)->paymentDue($this->utc('2026-11-20 10:00:00')));
        self::assertNull($this->event()->paymentDue(null, $this->utc('2026-11-25 10:00:00')));
    }
}
