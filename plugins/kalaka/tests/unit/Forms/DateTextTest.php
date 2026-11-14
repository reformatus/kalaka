<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\DateText;
use PHPUnit\Framework\TestCase;

/** D6, D25: the text of a day. */
final class DateTextTest extends TestCase
{
    public function testEveryMonthHasItsHungarianNameInLowerCase(): void
    {
        $names = [
            'január', 'február', 'március', 'április', 'május', 'június',
            'július', 'augusztus', 'szeptember', 'október', 'november', 'december',
        ];

        foreach ($names as $index => $name) {
            self::assertSame(sprintf('2026. %s 5.', $name), DateText::of(sprintf('2026-%02d-05', $index + 1)));
        }
    }

    public function testTheDayHasNoLeadingZero(): void
    {
        self::assertSame('2026. november 30.', DateText::of('2026-11-30'));
        self::assertSame('2027. január 1.', DateText::of('2027-01-01'));
    }

    /** @return iterable<string, array{string}> */
    public static function notDays(): iterable
    {
        yield 'empty' => [''];
        yield 'no calendar day' => ['2026-02-30'];
        yield 'not padded' => ['2026-1-1'];
    }

    /** @dataProvider notDays */
    public function testAnythingButACalendarDayIsRefused(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DateText::of($text);
    }
}
