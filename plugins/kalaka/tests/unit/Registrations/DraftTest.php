<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Registrations;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Registrations\Draft\Draft;
use PHPUnit\Framework\TestCase;

/** The one test of "a guest draft of the form" (S74): no time of its own; owner ruling 2026-11-26. */
final class DraftTest extends TestCase
{
    private function draft(?int $user, int $form = 7): Draft
    {
        $at = new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));

        return new Draft(1, $form, 1, $user, 'hash', new AnswerSet([]), 0, $at, $at);
    }

    public function testAGuestDraftIsOfItsFormWhateverItsAge(): void
    {
        $guest = $this->draft(null);

        self::assertTrue($guest->isGuestOf(7));
        self::assertFalse($guest->isGuestOf(8));
    }

    public function testAUserDraftIsNeverAGuestDraft(): void
    {
        self::assertFalse($this->draft(5)->isGuestOf(7));
    }
}
