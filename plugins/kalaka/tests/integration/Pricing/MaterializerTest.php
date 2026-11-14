<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The walkthrough's cases C1, C2, C4, C5 and C7, with no tariff snapshot: money follows the current settings. */
final class MaterializerTest extends PricingTestCase
{
    public function testAPlainFixedPriceIsChargedOnceAndConverges(): void
    {
        $this->setPrice('40000');
        $anna = $this->register('00A0');

        self::assertSame(['fixed_price participant 40000 charge:fixed_price:participant:1'], $this->charges($anna));

        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));
        self::assertCount(1, $this->charges($anna));

        $this->pay($anna, '40000');
        self::assertCount(1, $this->charges($anna));
        self::assertSame('0', $this->balance($anna));
    }

    public function testWithoutAPriceNothingIsCharged(): void
    {
        $anna = $this->register('00A0');

        self::assertSame([], $this->charges($anna));
    }

    public function testAWaitlistedRegistrationOwesNothingAndPaysTodaysPriceWhenAdmitted(): void
    {
        $this->setPrice('40000');
        $bence = $this->register('00A2', RegistrationStatus::Waitlisted);
        self::assertSame([], $this->charges($bence));

        $this->setPrice('45000');
        self::assertSame([], $this->charges($bence));

        $this->setStatus($bence, RegistrationStatus::Registered);
        self::assertSame(['fixed_price participant 45000 charge:fixed_price:participant:1'], $this->charges($bence));
    }

    public function testAWithdrawalOwesZeroAndShowsTheOverpayment(): void
    {
        $this->setPrice('40000');
        $dora = $this->register('00A5');
        $this->pay($dora, '15000');

        $this->setStatus($dora, RegistrationStatus::Withdrawn);

        self::assertSame([
            'fixed_price participant 40000 charge:fixed_price:participant:1',
            'fixed_price participant -40000 charge:fixed_price:participant:2',
        ], $this->charges($dora));
        self::assertSame('-15000', $this->balance($dora));
    }

    public function testStagesComposeInOrderAndASecondPayerHasItsOwnBalance(): void
    {
        $this->useComponents(new ShareComponent('35000'), new SupportComponent('8000'));
        $this->setPrice('40000');
        $emese = $this->register('00A8');

        self::assertSame([
            'fixed_price participant 40000 charge:fixed_price:participant:1',
            'share congregation 32000 charge:share:congregation:1',
            'share participant -32000 charge:share:participant:1',
            'support participant -8000 charge:support:participant:1',
        ], $this->charges($emese));
        self::assertSame('0', $this->balance($emese));
        self::assertSame('32000', $this->balance($emese, 'congregation'));

        $this->pay($emese, '33000', 'congregation');
        self::assertSame('-1000', $this->balance($emese, 'congregation'));
        self::assertSame('0', $this->balance($emese));
    }

    public function testLinesOfAComponentNoLongerRegisteredAreLeftAlone(): void
    {
        $this->useComponents(new SupportComponent('8000'));
        $this->setPrice('40000');
        $emese = $this->register('00A8');
        self::assertCount(2, $this->charges($emese));

        $this->useComponents();
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($emese));

        self::assertCount(2, $this->charges($emese));
        self::assertSame('32000', $this->balance($emese));
    }

    public function testAFailingComponentFailsTheUseCaseAsTheRuleMoneyAndItsId(): void
    {
        $flaky = new FailingComponent('00A0');
        $flaky->failing = true;
        $this->useComponents($flaky);

        try {
            $this->register('00A0');
            self::fail('The registration was written.');
        } catch (SystemFailure $failure) {
            $rule = $failure->getPrevious();
            self::assertInstanceOf(RuleFailed::class, $rule);
            self::assertSame('money/flaky', $rule->getMessage());
            self::assertInstanceOf(FeeComponentFailed::class, $rule->getPrevious());
        }
        self::assertSame(0, $this->registrationCount());
    }
}
