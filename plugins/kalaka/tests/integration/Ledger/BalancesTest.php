<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Registrations\Api\RegistrationKey;

/** AC5: balances are the sums of the lines, per payer and in total. */
final class BalancesTest extends LedgerTestCase
{
    /**
     * @param list<array{LineKind, string, string}> $lines kind, amount, payer
     */
    private function balancesAfter(array $lines, string $code = '12A3'): \Kalaka\Ledger\Api\Balances
    {
        $registration = $this->registration($code);
        $new = [];
        foreach ($lines as $number => [$kind, $amount, $payer]) {
            $new[] = $this->line(
                $registration,
                $kind,
                $amount,
                'line:' . $number,
                $kind->carriesValueDate() ? '2026-12-03' : null,
                $kind->carriesPaidTime() ? '2026-12-01 10:00:00' : null,
                $payer
            );
        }
        $this->record(...$new);

        return $this->ledger->balancesOf($registration);
    }

    public function testAnExactPaymentSettlesTheBalance(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '35500', Payer::PARTICIPANT],
            [LineKind::ManualPayment, '-35500', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->of(Payer::PARTICIPANT, Currency::HUF)->isEqualTo($this->money('0')));
        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('0')));
        self::assertTrue($balances->total(Currency::HUF)->countsAsZero());
    }

    public function testAnOverpaymentIsNegative(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '35500', Payer::PARTICIPANT],
            [LineKind::ManualPayment, '-40000', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->of(Payer::PARTICIPANT, Currency::HUF)->isEqualTo($this->money('-4500')));
        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('-4500')));
        self::assertTrue($balances->total(Currency::HUF)->isNegative());
    }

    public function testAnOnlinePaymentCountsLikeAManualOne(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '30000', Payer::PARTICIPANT],
            [LineKind::Payment, '-35000', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('-5000')));
    }

    public function testManualAndOnlinePaymentsAddUp(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '56000', Payer::PARTICIPANT],
            [LineKind::ManualPayment, '-20000', Payer::PARTICIPANT],
            [LineKind::Payment, '-36000', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('0')));
    }

    public function testChargesFromSeveralComponentsAddUp(): void
    {
        $registration = $this->registration();
        $this->record(
            $this->line($registration, LineKind::Charge, '48000', 'c:1', null, null, Payer::PARTICIPANT, 'fixed_price'),
            $this->line(
                $registration,
                LineKind::Charge,
                '-10000',
                'c:2',
                null,
                null,
                Payer::PARTICIPANT,
                'staff_discount'
            ),
            $this->line($registration, LineKind::Charge, '-5000', 'c:3', null, null, Payer::PARTICIPANT, 'early_bird'),
            $this->line($registration, LineKind::Charge, '3500', 'c:4', null, null, Payer::PARTICIPANT, 'lunch'),
            $this->line($registration, LineKind::Charge, '2000', 'c:5', null, null, Payer::PARTICIPANT, 'late_fee'),
        );

        self::assertTrue(
            $this->ledger->balancesOf($registration)->total(Currency::HUF)->isEqualTo($this->money('38500'))
        );
    }

    public function testChargesAndADiscountAddUp(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '33000', Payer::PARTICIPANT],
            [LineKind::Charge, '55000', Payer::PARTICIPANT],
            [LineKind::Charge, '-10000', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('78000')));
    }

    public function testEachPayerHasItsOwnBalanceAndTheTotalIsTheirSum(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '4800', Payer::PARTICIPANT],
            [LineKind::Charge, '9600', 'congregation'],
            [LineKind::Payment, '-9600', 'congregation'],
        ]);

        self::assertTrue($balances->of(Payer::PARTICIPANT, Currency::HUF)->isEqualTo($this->money('4800')));
        self::assertTrue($balances->of('congregation', Currency::HUF)->isEqualTo($this->money('0')));
        self::assertTrue($balances->total(Currency::HUF)->isEqualTo($this->money('4800')));
        self::assertSame(['congregation', 'participant'], array_keys($balances->byPayer()));
        self::assertTrue($balances->of('sponsor', Currency::HUF)->isEqualTo($this->money('0')));
    }

    public function testAFractionBelowAFillerCountsAsZero(): void
    {
        $balances = $this->balancesAfter([
            [LineKind::Charge, '1000.004', Payer::PARTICIPANT],
            [LineKind::ManualPayment, '-1000', Payer::PARTICIPANT],
        ]);

        self::assertTrue($balances->of(Payer::PARTICIPANT, Currency::HUF)->isEqualTo($this->money('0.004')));
        self::assertTrue($balances->total(Currency::HUF)->countsAsZero());
    }

    /** #42 AC13: `HUF` is the only `Currency` case, so the test shows the currency passed in comes back. */
    public function testARegistrationWithoutLinesHasNoPayersAndZeroInTheCurrencyPassedIn(): void
    {
        $registration = $this->registration();

        $balances = $this->ledger->balancesOf($registration);

        self::assertSame([], $balances->byPayer());
        foreach (Currency::cases() as $currency) {
            $total = $balances->total($currency);
            self::assertTrue($total->isEqualTo(Money::zero($currency)));
            self::assertSame($currency, $total->currency());
            $ofParticipant = $balances->of(Payer::PARTICIPANT, $currency);
            self::assertTrue($ofParticipant->isEqualTo(Money::zero($currency)));
            self::assertSame($currency, $ofParticipant->currency());
        }
    }

    public function testLinesOfOtherRegistrationsNeverCount(): void
    {
        $mine = $this->registration('12A3');
        $sameEventOther = $this->registration('12A4');
        $otherEventSameCode = $this->registration('12A3', self::EVENT + 1);
        $this->record(
            $this->line($mine, LineKind::Charge, '1000', 'k1'),
            $this->line($sameEventOther, LineKind::Charge, '200', 'k1'),
            $this->line($otherEventSameCode, LineKind::Charge, '30', 'k1'),
        );

        self::assertTrue($this->ledger->balancesOf($mine)->total(Currency::HUF)->isEqualTo($this->money('1000')));
        self::assertTrue(
            $this->ledger->balancesOf($sameEventOther)->total(Currency::HUF)->isEqualTo($this->money('200'))
        );
        self::assertTrue(
            $this->ledger->balancesOf(new RegistrationKey(self::EVENT + 1, '12A3'))
                ->total(Currency::HUF)
                ->isEqualTo($this->money('30'))
        );
    }
}
