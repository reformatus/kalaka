<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\CardAmountLimits;
use Kalaka\Payments\Api\CardAmountRefused;
use Kalaka\Payments\Api\CardPaymentInFlight;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Tests\Integration\Portal\PortalTestCase;

/** The amount a card payment asks for: its default, its limits and what is refused. */
final class CardAmountTest extends PortalTestCase
{
    private FakeProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CardAmountLimits::OPTION);
        $this->provider = new FakeProvider();
        $contributions = new Contributions();
        $contributions->paymentProvider($this->provider);
        Contributions::record($contributions);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        delete_option(CardAmountLimits::OPTION);

        parent::tear_down();
    }

    private function asked(): string
    {
        return rtrim(rtrim($this->provider->requests[0]->lines[0]->amount->amount(), '0'), '.');
    }

    private static function huf(string $amount): Money
    {
        return Money::of($amount, Currency::HUF);
    }

    private function refusedWith(string $amount, string $min, string $max): void
    {
        $key = new \Kalaka\Registrations\Api\RegistrationKey(self::EVENT, '12A3');
        try {
            (new CardPayments())->start($key, null, $amount);
            self::fail('Nothing refused it.');
        } catch (CardAmountRefused $e) {
            self::assertSame('kalaka_card_amount_refused', $e->errorCode());
            self::assertSame(422, $e->status());
            self::assertSame($min, rtrim(rtrim($e->min->amount(), '0'), '.'));
            self::assertSame($max, rtrim(rtrim($e->max->amount(), '0'), '.'));
            self::assertSame([], $this->provider->requests);
        }
    }

    public function testAnAbsentAmountAsksForTheFullBalanceRoundedUp(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000.40');

        (new CardPayments())->start($key);

        self::assertSame('12001', $this->asked());
    }

    public function testAValidAmountBetweenTheLimitsIsAskedAsItIs(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        (new CardPayments())->start($key, null, '5000');

        self::assertSame('5000', $this->asked());
        self::assertSame('HUF', $this->provider->requests[0]->currency->value);
    }

    public function testTheMinimumAndTheFullBalanceThemselvesAreAccepted(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');
        $payments = new CardPayments();

        $payments->start($key, null, '1000');
        $payments->start($key, null, '12000.00');

        self::assertSame('1000', rtrim(rtrim($this->provider->requests[0]->lines[0]->amount->amount(), '0'), '.'));
        self::assertSame('12000', rtrim(rtrim($this->provider->requests[1]->lines[0]->amount->amount(), '0'), '.'));
    }

    public function testAnAmountAboveTheFullBalanceIsRefusedWithTheLimits(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        $this->refusedWith('12001', '1000', '12000');
    }

    public function testAnAmountBelowTheMinimumIsRefusedWithTheLimits(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        $this->refusedWith('999', '1000', '12000');
    }

    public function testZeroIsRefused(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        $this->refusedWith('0', '1000', '12000');
    }

    public function testWhenTheBalanceIsBelowTheMinimumOnlyExactlyTheBalanceIsAccepted(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '400');

        $this->refusedWith('399', '400', '400');
        (new CardPayments())->start($key, null, '400');

        self::assertSame('400', $this->asked());
    }

    public function testACurrencyWithoutAMinimumAcceptsTheSmallestPayableAmount(): void
    {
        update_option(CardAmountLimits::OPTION, ['EUR' => '5']);
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        $this->refusedWith('0', '1', '12000');
        (new CardPayments())->start($key, null, '1');

        self::assertSame('1', $this->asked());
    }

    public function testTheSiteMinimumIsTheOptionsAndAnEmptyMapMeansNone(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        update_option(CardAmountLimits::OPTION, ['HUF' => '2500']);
        $this->refusedWith('2499', '2500', '12000');

        update_option(CardAmountLimits::OPTION, '{"HUF": "3000"}');
        $this->refusedWith('2999', '3000', '12000');

        update_option(CardAmountLimits::OPTION, []);
        $limits = CardAmountLimits::of(self::huf('12000'));
        self::assertTrue($limits->min->isEqualTo(self::huf('1')));
    }

    /** @return array<string, array{mixed}> */
    public static function malformedOptions(): array
    {
        return [
            'not an array' => [7],
            'not JSON' => ['hello'],
            'a value that is no decimal' => [['HUF' => 'abc']],
            'a negative value' => [['HUF' => '-5']],
            'a value that is a list' => [['HUF' => ['1000']]],
            'a list, not a map' => [['1000']],
        ];
    }

    /** @dataProvider malformedOptions */
    public function testAMalformedOptionIsTheDefaultMapAndIsReported(mixed $stored): void
    {
        update_option(CardAmountLimits::OPTION, $stored);

        $limits = CardAmountLimits::of(self::huf('12000'));

        self::assertTrue($limits->min->isEqualTo(self::huf('1000')));
        self::assertTrue($limits->max->isEqualTo(self::huf('12000')));
    }

    public function testAnAbsentOptionIsTheDefaultMap(): void
    {
        $limits = CardAmountLimits::of(self::huf('12000'));

        self::assertTrue($limits->min->isEqualTo(self::huf('1000')));
    }

    /** @return array<string, array{string}> */
    public static function malformedAmounts(): array
    {
        return [
            'not a number' => ['abc'],
            'negative' => ['-1000'],
            'an empty text' => [''],
            'a comma' => ['1000,5'],
            'below the minor unit' => ['1000.005'],
            'a fraction of a forint' => ['1500.50'],
            'a fraction of a fillér too small' => ['1500.001'],
            'too many digits' => ['1.123456789'],
        ];
    }

    /** @dataProvider malformedAmounts */
    public function testAMalformedAmountIsInvalidInput(string $amount): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');

        try {
            (new CardPayments())->start($key, null, $amount);
            self::fail('Nothing refused it.');
        } catch (InvalidInput $e) {
            self::assertSame('kalaka_invalid_input', $e->errorCode());
            self::assertSame([], $this->provider->requests);
        }
    }

    public function testNothingPayableIsStillUnavailableWhateverTheAmount(): void
    {
        $key = $this->stored('12A3');

        $this->expectException(CardPaymentUnavailable::class);
        (new CardPayments())->start($key, null, '5000');
    }

    public function testARequestInFlightIsRefusedBeforeTheAmountIsChecked(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000');
        $this->provider->latest = new OpenPayment(
            OpenPaymentState::Processing,
            new \DateTimeImmutable('2026-11-30 09:30:00'),
            '501'
        );

        $this->expectException(CardPaymentInFlight::class);
        (new CardPayments())->start($key, null, '99999999');
    }
}
