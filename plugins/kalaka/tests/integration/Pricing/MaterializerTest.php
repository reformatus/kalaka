<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Forms\Api\Value;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Evaluation\Evaluations;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Evaluation\EvaluationFailureRepository;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeRefusal;
use Kalaka\Pricing\Api\FeeRefused;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Foundation\PortalProbe;

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

    public function testTheFixedPriceIsChargedOnlyWhenNoComponentProvidesTheBase(): void
    {
        $ladder = new TierComponent('49500', 'tier', true);
        $this->useComponents($ladder);
        $this->setPrice('40000');
        $anna = $this->register('00A0');

        // A ladder with a tier owing X: only X.
        self::assertSame(['tier participant 49500 charge:tier:participant:1'], $this->charges($anna));

        // A ladder whose tier owes nothing: the fixed price is still not charged.
        $ladder->amount = null;
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));

        self::assertSame([
            'tier participant 49500 charge:tier:participant:1',
            'tier participant -49500 charge:tier:participant:2',
        ], $this->charges($anna));
        self::assertSame('0', $this->balance($anna));

        // No ladder: the fixed price is the base.
        $ladder->base = false;
        $this->inRun(static fn (array $input, Context $context) => $context->markTouched($anna));

        self::assertSame(
            'fixed_price participant 40000 charge:fixed_price:participant:1',
            $this->charges($anna)[2]
        );
        self::assertSame('40000', $this->balance($anna));
    }

    public function testAComputedValueReachesTheLaterStagesOnlyAndIsNeverStored(): void
    {
        $seen = [];
        $tier = new ClosureComponent('tier', FeeStage::Tier, static fn (): FeeTargets => new FeeTargets(
            [Payer::PARTICIPANT => Money::of('1000', Currency::HUF)],
            ['tier.applied' => Value::choice(['alap'], 'Alap ár')]
        ));
        $sameStage = new ClosureComponent(
            'tier_too',
            FeeStage::Tier,
            static function (FeeComponentInput $input) use (&$seen): FeeTargets {
                $seen['tier'] = $input->facts->has('tier.applied');

                return FeeTargets::none();
            }
        );
        $share = new ClosureComponent(
            'share',
            FeeStage::Share,
            static function (FeeComponentInput $input) use (&$seen): FeeTargets {
                $seen['share'] = $input->facts->get('tier.applied')->data;

                return FeeTargets::none();
            }
        );
        $this->useComponents($tier, $sameStage, $share);

        $anna = $this->register('00A0');

        self::assertSame(['tier' => false, 'share' => ['alap']], $seen);
        self::assertSame(['tier participant 1000 charge:tier:participant:1'], $this->charges($anna));
    }

    public function testAComputedValueMayNotTakeAKeyTheFactsHave(): void
    {
        $this->useComponents(new ClosureComponent('tier', FeeStage::Tier, static fn (): FeeTargets => new FeeTargets(
            [],
            ['registration.code' => Value::text('x', 'x')]
        )));

        try {
            $this->register('00A0');
            self::fail('The registration was written.');
        } catch (SystemFailure $failure) {
            self::assertSame('money/tier', $failure->getPrevious()?->getMessage());
        }
    }

    public function testARefusingComponentRefusesWithItsTextForTheActorsSideAndThePeriodicRunFlagsIt(): void
    {
        $refusing = true;
        $refuse = static function () use (&$refusing): FeeTargets {
            if ($refusing) {
                throw new FeeRefusal('Munkatársi szöveg.', 'Jelentkezői szöveg.', 'test_refusal');
            }

            return FeeTargets::none();
        };
        $this->useComponents(new ClosureComponent('tier', FeeStage::Tier, $refuse));

        // Staff: the admin text, a 422 that names the rule.
        try {
            $this->register('00A0');
            self::fail('The registration was written.');
        } catch (FeeRefused $refused) {
            self::assertInstanceOf(RuleViolation::class, $refused);
            self::assertSame(422, $refused->status());
            self::assertSame('Munkatársi szöveg.', $refused->publicMessage());
            self::assertSame('money/tier', $refused->getPrevious()?->getMessage());
        }
        self::assertSame(0, $this->registrationCount());

        // The registrant: the portal text.
        $refusing = false;
        $anna = $this->register('00A0');
        $refusing = true;
        try {
            $this->runner->run(
                new PortalProbe([$anna], static fn (array $input, Context $context) => $context->markTouched($anna)),
                [],
                new SecretLinkActor($anna)
            );
            self::fail('The run was not refused.');
        } catch (FeeRefused $refused) {
            self::assertSame('Jelentkezői szöveg.', $refused->publicMessage());
        }

        // The periodic run: the "evaluation failed" flag names the rule; a later success clears it.
        $failures = new EvaluationFailureRepository();
        try {
            (new Evaluations($this->runner))->evaluate($anna, new SystemActor());
            self::fail('The evaluation succeeded.');
        } catch (FeeRefused) {
            self::assertSame('money/tier', $failures->find($anna)?->rule);
        }
        $refusing = false;
        (new Evaluations($this->runner))->evaluate($anna, new SystemActor());
        self::assertNull($failures->find($anna));
    }

    public function testTwoComponentsWithOneIdAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new CoreFeeComponents([new TierComponent('1', 'fixed_price')]))->list();
    }
}
