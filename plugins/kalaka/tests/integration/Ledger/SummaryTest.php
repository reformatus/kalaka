<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\Payer;

/** `Ledger::summaryOf()`: per payer charged, paid and balance, and the lines that are not payment facts. */
final class SummaryTest extends LedgerTestCase
{
    public function testEachPayerHasItsChargedPaidAndBalanceAndItsChargeLinesInLedgerOrder(): void
    {
        $key = $this->registration();
        $other = $this->registration('00A0');
        $this->record(
            $this->line($key, LineKind::Charge, '48000', 'a'),
            $this->line($key, LineKind::Adjustment, '-10000', 'b', sourceType: 'adjustment'),
            $this->fitted($key, LineKind::ManualPayment, '-20000', 'c'),
            $this->fitted($key, LineKind::ManualRefund, '500', 'd'),
            $this->line($key, LineKind::Charge, '12000', 'e', payer: 'congregation'),
            $this->line($key, LineKind::Charge, '3000.25', 'f'),
            $this->line($other, LineKind::Charge, '99', 'g'),
        );

        $summary = $this->ledger->summaryOf($key);

        self::assertSame(['congregation', Payer::PARTICIPANT], array_keys($summary->payers()));
        $participant = $summary->of(Payer::PARTICIPANT, Currency::HUF);
        self::assertSame('41000.25000000', $participant->charged->amount());
        self::assertSame('19500.00000000', $participant->paid->amount());
        self::assertSame('21500.25000000', $participant->balance->amount());
        self::assertSame(
            $this->ledger->balancesOf($key)->of(Payer::PARTICIPANT, Currency::HUF)->amount(),
            $participant->balance->amount()
        );
        self::assertSame(
            ['48000.00000000', '-10000.00000000', '3000.25000000'],
            array_map(
                static fn ($line): string => $line->amount->amount(),
                $summary->chargeLinesOf(Payer::PARTICIPANT)
            )
        );
        self::assertSame('adjustment', $summary->chargeLinesOf(Payer::PARTICIPANT)[1]->source->type);
        $congregation = $summary->of('congregation', Currency::HUF);
        self::assertSame('12000.00000000', $congregation->balance->amount());
    }

    public function testWithoutLinesEveryAmountIsZeroAndOneStatementReadsIt(): void
    {
        $key = $this->registration();

        $statements = $this->statementsDuring(function () use ($key, &$summary): void {
            $summary = $this->ledger->summaryOf($key);
        });

        self::assertCount(1, $statements);
        self::assertSame([], $summary->payers());
        self::assertTrue($summary->of(Payer::PARTICIPANT, Currency::HUF)->balance->countsAsZero());
        self::assertSame([], $summary->chargeLinesOf(Payer::PARTICIPANT));
    }
}
