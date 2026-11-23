<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Portal;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\MovementRow;
use Kalaka\Portal\Api\MovementType;
use Kalaka\Portal\MoneyWords;
use PHPUnit\Framework\TestCase;

/** The words the money table of the page and of the mail share (owner topics 10 and 11). */
final class MoneyWordsTest extends TestCase
{
    public function testTheTypeWordsAreTheOwnersAndTheNoteIsQuotedTheHungarianWay(): void
    {
        self::assertSame('Befizetés', MoneyWords::typeOf(MovementType::Payment));
        self::assertSame('Visszafizetés', MoneyWords::typeOf(MovementType::Refund));
        self::assertSame('Korrekció', MoneyWords::typeOf(MovementType::Correction));
        self::assertSame("\u{201E}TAB27 12A3\u{201D}", MoneyWords::quoted('TAB27 12A3'));
    }

    public function testANoteIsCutAtThirtySixCharactersAndAShortOneStaysWhole(): void
    {
        $exact = str_repeat('á', 36);
        self::assertSame($exact, MoneyWords::shortened($exact));
        self::assertSame('közlemény helytelen volt: nem volt b…', MoneyWords::shortened(
            'közlemény helytelen volt: nem volt benne az azonosító'
        ));
        // A cut that ends on a space drops it, so „…” sits on the last letter.
        self::assertSame('12345678901234567890123456789012345…', MoneyWords::shortened(
            '12345678901234567890123456789012345 5678'
        ));
    }

    public function testTheDayReadsYearMonthDayWithDots(): void
    {
        self::assertSame('2026. 11. 05.', MoneyWords::day('2026-11-05'));
        self::assertSame('nem nap', MoneyWords::day('nem nap'));
    }

    public function testTheSignFollowsTheAmountAndTheAmountLosesIt(): void
    {
        $minus = Money::of('-4000', Currency::HUF);
        self::assertSame("\u{2212}", MoneyWords::sign($minus));
        self::assertSame('+', MoneyWords::sign(Money::of('4000', Currency::HUF)));
        self::assertSame('4000.00000000', MoneyWords::absolute($minus)->amount());
    }

    public function testTheHeadlineIsTypeMethodDayAndACorrectionHasNoMethod(): void
    {
        $money = Money::of('1000', Currency::HUF);
        self::assertSame(
            'Visszafizetés · Átutalás · 2026. 12. 05.',
            MoneyWords::headline(new MovementRow(MovementType::Refund, null, 'Átutalás', null, '2026-12-05', $money))
        );
        self::assertSame(
            'Korrekció · 2026. 12. 05.',
            MoneyWords::headline(new MovementRow(MovementType::Correction, null, null, 'ok', '2026-12-05', $money))
        );
    }
}
