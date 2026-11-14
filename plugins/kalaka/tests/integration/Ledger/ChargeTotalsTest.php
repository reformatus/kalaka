<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Ledger\Api\ChargeTotal;
use Kalaka\Ledger\Api\LineKind;

/** `chargeTotalsOf()`: the charge lines summed per source and payer; adjustments and payments never count. */
final class ChargeTotalsTest extends LedgerTestCase
{
    public function testChargesAreSummedPerSourceAndPayerAndNothingElseCounts(): void
    {
        $anna = $this->registration();
        $other = $this->registration('00A0');
        $this->record(
            $this->line($anna, LineKind::Charge, '40000', 'charge:fixed_price:participant:1'),
            $this->line($anna, LineKind::Charge, '5000', 'charge:fixed_price:participant:2'),
            $this->line($anna, LineKind::Charge, '-20000', 'charge:share:participant:1', source: 'share'),
            $this->line(
                $anna,
                LineKind::Charge,
                '20000',
                'charge:share:congregation:1',
                payer: 'congregation',
                source: 'share'
            ),
            $this->line($anna, LineKind::Adjustment, '-1000', 'adjustment:1', source: 'fixed_price'),
            $this->fitted($anna, LineKind::ManualPayment, '-30000', 'pay:1'),
            $this->line($other, LineKind::Charge, '40000', 'charge:fixed_price:participant:1'),
        );

        $totals = array_map(
            static fn (ChargeTotal $total): string => $total->source->type . '/' . $total->source->key . ' '
                . $total->payer . ' ' . rtrim(rtrim($total->total->amount(), '0'), '.') . ' ' . $total->lines,
            $this->ledger->chargeTotalsOf($anna)
        );

        self::assertSame([
            'fee_component/fixed_price participant 45000 2',
            'fee_component/share congregation 20000 1',
            'fee_component/share participant -20000 1',
        ], $totals);
    }
}
