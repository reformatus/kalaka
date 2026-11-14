<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Engine\PrefillHandoff;
use WP_UnitTestCase;

/** S15-5: the prefill kept across the login under a short-lived token: stored, taken once, expired. */
final class PrefillHandoffTest extends WP_UnitTestCase
{
    private const PREFILL = ['modul' => 'zene', 'email' => 'teszt.dora@example.org'];

    private static function utc(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function testTheTokenIsUrlSafeAndThePrefillIsNotInIt(): void
    {
        $token = (new PrefillHandoff())->keep(7, self::PREFILL, self::utc('2026-11-30 08:00:00'));

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\z/', $token);
        self::assertStringNotContainsString('teszt', $token);
        global $wpdb;
        // Stored under its hash only.
        self::assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%' . $wpdb->esc_like($token) . '%'
        )));
    }

    public function testThePrefillIsTakenOnceForItsForm(): void
    {
        $handoff = new PrefillHandoff();
        $token = $handoff->keep(7, self::PREFILL, self::utc('2026-11-30 08:00:00'));

        self::assertNull($handoff->take(8, $token, self::utc('2026-11-30 08:05:00')));
        // A token tried on another form is spent too.
        self::assertNull($handoff->take(7, $token, self::utc('2026-11-30 08:05:00')));

        $token = $handoff->keep(7, self::PREFILL, self::utc('2026-11-30 08:00:00'));
        self::assertSame(self::PREFILL, $handoff->take(7, $token, self::utc('2026-11-30 08:29:59')));
        self::assertNull($handoff->take(7, $token, self::utc('2026-11-30 08:30:00')));
    }

    public function testAKeptPrefillExpiresAfterThirtyMinutesOnTheClock(): void
    {
        $handoff = new PrefillHandoff();
        $token = $handoff->keep(7, self::PREFILL, self::utc('2026-11-30 08:00:00'));

        self::assertNull($handoff->take(7, $token, self::utc('2026-11-30 08:30:00')));
    }

    public function testAMalformedOrUnknownTokenGivesNothing(): void
    {
        $handoff = new PrefillHandoff();

        self::assertNull($handoff->take(7, 'nem-token', self::utc('2026-11-30 08:00:00')));
        self::assertNull($handoff->take(7, str_repeat('A', 22), self::utc('2026-11-30 08:00:00')));
    }
}
