<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Ledger;

use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\Side;
use PHPUnit\Framework\TestCase;

/** money.md, "Ledger": every line kind declares a side, and the paid side is the payment facts. */
final class LineKindSideTest extends TestCase
{
    public function testOnlyTheChargeIsOwedAndEveryOtherKindIsPaid(): void
    {
        $sides = [];
        foreach (LineKind::cases() as $kind) {
            $sides[$kind->value] = $kind->side();
            self::assertSame($kind->side() === Side::Paid, $kind->isPaymentFact(), $kind->value);
        }

        self::assertSame([
            'charge' => Side::Owed,
            'payment' => Side::Paid,
            'refund' => Side::Paid,
            'manual_payment' => Side::Paid,
            'manual_refund' => Side::Paid,
        ], $sides);
    }
}
