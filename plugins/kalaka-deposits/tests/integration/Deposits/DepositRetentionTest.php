<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Deposits;

use Kalaka\Deposits\DepositSettings;
use Kalaka\Deposits\Retention\DepositRetention;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\RetentionInput;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Tests\Integration\Events\EventsTestCase;

/** The `deposit` retention rule: the deposit is shared by the payers, the participant first. */
final class DepositRetentionTest extends EventsTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        delete_option(DepositSettings::OPTION);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        delete_option(DepositSettings::OPTION);
        parent::tear_down();
    }

    private function huf(string $amount): Money
    {
        return Money::of($amount, Currency::HUF);
    }

    /**
     * @param array<string, Money> $kept
     * @return array<string, string>
     */
    private function texts(array $kept): array
    {
        return array_map(static fn (Money $money): string => $money->amount(), $kept);
    }

    /** @param array<string, Money> $paid */
    private function input(int $eventId, array $paid): RetentionInput
    {
        $event = (new Events())->find($eventId);
        self::assertNotNull($event);
        $facts = new RegistrationFacts(
            new RegistrationKey($eventId, ReferenceCode::fourCharacterCodes()[0]),
            RegistrationStatus::Withdrawn,
            null,
            $event,
            new \DateTimeImmutable('2026-11-27 10:00:00', new \DateTimeZone('UTC')),
        );

        return new RetentionInput($facts, Currency::HUF, $paid, null);
    }

    public function testTheRuleIsCalledDeposit(): void
    {
        self::assertSame('deposit', (new DepositRetention())->id());
    }

    public function testADepositBelowThePaymentsKeepsTheDeposit(): void
    {
        $kept = DepositRetention::cap([Payer::PARTICIPANT => $this->huf('30000')], $this->huf('10000'));

        self::assertSame([Payer::PARTICIPANT => $this->huf('10000')->amount()], $this->texts($kept));
    }

    public function testADepositAboveThePaymentsKeepsEverythingPaid(): void
    {
        $kept = DepositRetention::cap([Payer::PARTICIPANT => $this->huf('4000')], $this->huf('10000'));

        self::assertSame([Payer::PARTICIPANT => $this->huf('4000')->amount()], $this->texts($kept));
    }

    public function testADepositEqualToThePaymentsKeepsEverythingPaid(): void
    {
        $kept = DepositRetention::cap([Payer::PARTICIPANT => $this->huf('10000')], $this->huf('10000'));

        self::assertSame([Payer::PARTICIPANT => $this->huf('10000')->amount()], $this->texts($kept));
    }

    public function testTheParticipantIsCountedFirstWhateverTheKeyOrder(): void
    {
        $kept = DepositRetention::cap(
            ['church' => $this->huf('8000'), Payer::PARTICIPANT => $this->huf('6000')],
            $this->huf('10000'),
        );

        self::assertSame(
            [Payer::PARTICIPANT => $this->huf('6000')->amount(), 'church' => $this->huf('4000')->amount()],
            $this->texts($kept),
        );
    }

    public function testTheRestOfTheDepositGoesToTheOtherPayersInKeyOrder(): void
    {
        $kept = DepositRetention::cap(
            [
                'sponsor' => $this->huf('2000'),
                'church' => $this->huf('5000'),
                Payer::PARTICIPANT => $this->huf('1000'),
            ],
            $this->huf('6000'),
        );

        self::assertSame(
            [
                Payer::PARTICIPANT => $this->huf('1000')->amount(),
                'sponsor' => $this->huf('2000')->amount(),
                'church' => $this->huf('3000')->amount(),
            ],
            $this->texts($kept),
        );
    }

    public function testAPayerAfterTheDepositIsUsedUpIsLeftOut(): void
    {
        $kept = DepositRetention::cap(
            [Payer::PARTICIPANT => $this->huf('12000'), 'church' => $this->huf('5000')],
            $this->huf('10000'),
        );

        self::assertSame([Payer::PARTICIPANT => $this->huf('10000')->amount()], $this->texts($kept));
    }

    public function testNonPositivePaymentsAreSkipped(): void
    {
        $kept = DepositRetention::cap(
            [
                Payer::PARTICIPANT => $this->huf('0'),
                'church' => $this->huf('-3000'),
                'sponsor' => $this->huf('4000'),
            ],
            $this->huf('10000'),
        );

        self::assertSame(['sponsor' => $this->huf('4000')->amount()], $this->texts($kept));
    }

    public function testNoPaymentsKeepNothing(): void
    {
        self::assertSame([], DepositRetention::cap([], $this->huf('10000')));
    }

    public function testADepositWithDecimalsIsCountedExactly(): void
    {
        $kept = DepositRetention::cap(
            [Payer::PARTICIPANT => $this->huf('10000.30'), 'church' => $this->huf('5000')],
            $this->huf('10000.50'),
        );

        self::assertSame(
            [Payer::PARTICIPANT => $this->huf('10000.30')->amount(), 'church' => $this->huf('0.20')->amount()],
            $this->texts($kept),
        );
    }

    public function testAnEventWithoutADepositAmountKeepsNothing(): void
    {
        $event = $this->filled();

        $kept = (new DepositRetention())->kept($this->input($event, [Payer::PARTICIPANT => $this->huf('30000')]));

        self::assertSame([], $kept);
    }

    public function testTheEventsStoredDepositIsTheCap(): void
    {
        $event = $this->filled();
        $other = $this->filled('Másik tábor');
        (new DepositSettings())->set($event, '10000');
        (new DepositSettings())->set($other, '1');

        $kept = (new DepositRetention())->kept($this->input($event, [Payer::PARTICIPANT => $this->huf('30000')]));

        self::assertSame([Payer::PARTICIPANT => $this->huf('10000')->amount()], $this->texts($kept));
    }
}
