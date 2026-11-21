<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Pricing;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Pricing\Api\FeeTargets;
use PHPUnit\Framework\TestCase;

/** Lines kept apart (a component's one line per charge): `owed` is their sum per payer. */
final class FeeTargetsTest extends TestCase
{
    public function testOfLinesSumsTheLinesPerPayer(): void
    {
        $huf = static fn (string $amount): Money => Money::of($amount, Currency::HUF);

        $targets = FeeTargets::ofLines([
            'ebed' => ['participant' => $huf('3500')],
            'ejjel' => ['participant' => $huf('2000'), 'congregation' => $huf('100')],
        ]);

        self::assertSame('5500.00000000', $targets->owed['participant']->amount());
        self::assertSame('100.00000000', $targets->owed['congregation']->amount());
        self::assertSame(['ebed', 'ejjel'], array_keys($targets->lines));
        self::assertSame([], FeeTargets::none()->lines);
    }
}
