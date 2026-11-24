<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Registrations\Draft\Draft;
use PHPUnit\Framework\TestCase;

/** The one test of "a usable guest draft of the form" (S74). */
final class DraftTest extends TestCase
{
    private function draft(?int $user, ?\DateTimeImmutable $expires, int $form = 7): Draft
    {
        $at = new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));

        return new Draft(1, $form, 1, $user, 'hash', new AnswerSet([]), 0, $at, $at, $expires);
    }

    public function testAGuestDraftIsUsableForItsFormUntilItExpires(): void
    {
        $now = new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('UTC'));
        $live = $this->draft(null, $now->modify('+1 day'));
        $expired = $this->draft(null, $now->modify('-1 second'));

        self::assertTrue($live->isUsableGuestOf(7, $now));
        self::assertFalse($live->isUsableGuestOf(8, $now));
        self::assertTrue($live->isUsableGuest($now));
        self::assertFalse($expired->isUsableGuest($now));
        self::assertFalse($expired->isUsableGuestOf(7, $now));
        // The submit's deletion also takes an expired one.
        self::assertTrue($expired->isGuestOf(7));
        self::assertFalse($expired->isGuestOf(8));
    }

    public function testAUserDraftIsNeverAGuestDraft(): void
    {
        $now = new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('UTC'));
        $own = $this->draft(5, $now->modify('+1 day'));

        self::assertFalse($own->isGuestOf(7));
        self::assertFalse($own->isUsableGuest($now));
        self::assertFalse($this->draft(null, null)->isUsableGuest($now));
    }
}
