<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Pricing\Api\ConfirmationThreshold;
use Kalaka\Pricing\Api\ConfirmationThresholdInput;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * money.md, "Confirmation": the per-payer threshold, the transitions table, the effective time, "a later
 * charge or a higher threshold changes nothing", and withdrawal closing the sequence. Value dates are days in
 * Europe/Budapest, stored as 00:00 there: 2026-11-20 is 2026-11-19 23:00 UTC.
 */
final class ConfirmationTest extends ConfirmationTestCase
{
    public function testOnePayerPartialThenExactConfirmsAtTheValueDateOfThePaymentThatReachedIt(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        self::assertSame(['unconfirmed 2026-11-30 08:00:00 registration'], $this->confirmations($anna));

        $this->payOn($anna, '4000', '2026-11-20');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->payOn($anna, '6000', '2026-11-25');
        self::assertSame([
            'unconfirmed 2026-11-30 08:00:00 registration',
            'confirmed 2026-11-24 23:00:00 ledger_line',
        ], $this->confirmations($anna));
    }

    public function testAnOverpaymentConfirmsAtThatPayment(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '15000', '2026-11-21');

        self::assertSame('confirmed 2026-11-20 23:00:00 ledger_line', $this->confirmations($anna)[1]);
    }

    public function testBelowOneFillerShortCountsAsReached(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '9999.995', '2026-11-21');

        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    public function testAnOnlinePaymentCountsFromItsPaidTime(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOnline($anna, '10000', '2026-11-29 17:45:12');

        self::assertSame('confirmed 2026-11-29 17:45:12 ledger_line', $this->confirmations($anna)[1]);
    }

    public function testNothingDueConfirmsAtTheCheck(): void
    {
        $anna = $this->register('00A0');

        $records = $this->confirmations($anna);
        self::assertCount(1, $records);
        self::assertStringStartsWith('confirmed 2026-11-30 08:00:', $records[0]);
        self::assertStringEndsWith(' registration', $records[0]);
    }

    public function testTwoPayersMustEachReachTheirOwnTarget(): void
    {
        $this->useComponents(new ShareComponent('3000'));
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '7000', '2026-11-25');
        self::assertSame('unconfirmed', $this->confirmationState($anna), 'The congregation has not paid.');

        $this->payOn($anna, '3000', '2026-11-22', 'congregation');
        self::assertSame(
            'confirmed 2026-11-24 23:00:00 ledger_line',
            $this->confirmations($anna)[1],
            'The participant reached the last required target, on 2026-11-25.'
        );
    }

    public function testAParticipantOverpaymentNeverSettlesTheCongregation(): void
    {
        $this->useComponents(new ShareComponent('3000'));
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '10000', '2026-11-25');

        self::assertSame('unconfirmed', $this->confirmationState($anna));
    }

    public function testAPayerWhoOwesNothingIsNotRequired(): void
    {
        $this->useComponents(new ShareComponent('20000'));
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '10000', '2026-11-23', 'congregation');

        self::assertSame('confirmed 2026-11-22 23:00:00 ledger_line', $this->confirmations($anna)[1]);
    }

    public function testTheFlagConfirmsBelowAtItsSetTimeAndItsRevokeUnconfirms(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '2000', '2026-11-20');

        $this->setFlag($anna);
        $records = $this->confirmations($anna);
        self::assertCount(2, $records);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d manual_confirmation$/', $records[1]);

        $this->revokeFlag($anna);
        $records = $this->confirmations($anna);
        self::assertCount(3, $records);
        self::assertMatchesRegularExpression('/^unconfirmed 2026-11-30 08:00:\d\d manual_confirmation$/', $records[2]);
    }

    public function testTheFlagAboveTheThresholdChangesNothingAndItsRevokeKeepsTheConfirmation(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $before = $this->confirmations($anna);

        $this->setFlag($anna);
        $this->revokeFlag($anna);

        self::assertSame($before, $this->confirmations($anna));
    }

    public function testWithPaymentsAndTheFlagBothTheEarlierCounts(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0', RegistrationStatus::Waitlisted);
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setFlag($anna);
        $bela = $this->register('00A2', RegistrationStatus::Waitlisted);
        $this->setFlag($bela);
        $this->payOn($bela, '10000', '2026-12-05');

        $this->setStatus($anna, RegistrationStatus::Registered);
        $this->setStatus($bela, RegistrationStatus::Registered);

        self::assertSame(['confirmed 2026-11-19 23:00:00 ledger_line'], $this->confirmations($anna));
        self::assertMatchesRegularExpression(
            '/^confirmed 2026-11-30 08:00:\d\d manual_confirmation$/',
            $this->confirmations($bela)[0]
        );
    }

    public function testARefundBelowTheThresholdUnconfirmsAtItsValueDate(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->refundOn($anna, '1000', '2026-11-28');

        self::assertSame('unconfirmed 2026-11-27 23:00:00 ledger_line', $this->confirmations($anna)[2]);
    }

    public function testARefundBelowWithTheFlagSetKeepsTheConfirmation(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setFlag($anna);

        $this->refundOn($anna, '1000', '2026-11-28');

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(2, $this->confirmations($anna));
    }

    public function testARefundOfTheSurplusKeepsTheConfirmationAndALaterChargeChangesNothing(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '12000', '2026-11-20');
        $this->refundOn($anna, '2000', '2026-11-21');
        self::assertSame('confirmed', $this->confirmationState($anna));

        $this->setPrice('15000');

        self::assertSame('confirmed', $this->confirmationState($anna), 'A later charge changes nothing.');
        self::assertCount(2, $this->confirmations($anna));
    }

    public function testARevokeAtTheTargetThenALaterChargeChangesNothing(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setFlag($anna);
        $this->revokeFlag($anna);

        $this->setPrice('15000');
        $this->evaluate($anna);

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(2, $this->confirmations($anna));
    }

    public function testAfterALaterChargeARefundUnconfirms(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setPrice('15000');
        self::assertSame('confirmed', $this->confirmationState($anna));

        $this->refundOn($anna, '500', '2026-11-28');

        self::assertSame('unconfirmed', $this->confirmationState($anna), 'A refund takes the payments below.');
    }

    public function testAHigherThresholdChangesNothingAndALowerOneConfirmsAtTheNextEvaluation(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '4000', '2026-11-20');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->threshold = new class implements ConfirmationThreshold {
            public function threshold(ConfirmationThresholdInput $input): Money
            {
                return Money::of('3000', $input->target->currency());
            }
        };
        $this->useComponents();
        $this->evaluate($anna);
        self::assertSame('confirmed 2026-11-19 23:00:00 ledger_line', $this->confirmations($anna)[1]);

        $this->threshold = null;
        $this->useComponents();
        $this->evaluate($anna);
        self::assertSame('confirmed', $this->confirmationState($anna), 'A higher threshold changes nothing.');
    }

    public function testAThresholdAboveTheTargetIsHeldToTheTarget(): void
    {
        $this->threshold = new class implements ConfirmationThreshold {
            public function threshold(ConfirmationThresholdInput $input): Money
            {
                return $input->target->plus(Money::of('5000', $input->target->currency()));
            }
        };
        $this->useComponents();
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '10000', '2026-11-20');

        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    public function testWithdrawalClosesTheSequenceAndARestoreWritesANewRecord(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->setStatus($anna, RegistrationStatus::Withdrawn);
        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertCount(3, $this->confirmations($anna));

        $this->setStatus($anna, RegistrationStatus::Registered);
        $records = $this->confirmations($anna);
        self::assertCount(4, $records, 'A new record; the old one is never revived.');
        self::assertSame('confirmed 2026-11-19 23:00:00 ledger_line', $records[3]);
    }

    public function testRemovalClosesTooAndARestoreThatDoesNotQualifyStaysUnconfirmed(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setStatus($anna, RegistrationStatus::Removed);
        $this->setPrice('15000');

        $this->setStatus($anna, RegistrationStatus::Registered);

        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertCount(3, $this->confirmations($anna));
    }

    public function testAnUnconfirmedRegistrationThatWithdrawsGetsNoRecord(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->setStatus($anna, RegistrationStatus::Withdrawn);

        self::assertCount(1, $this->confirmations($anna));
    }

    public function testAWaitlistedRegistrationGetsNoDecisionAndAnAdmissionDecides(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0', RegistrationStatus::Waitlisted);
        $this->payOn($anna, '10000', '2026-11-20');
        self::assertSame([], $this->confirmations($anna));

        $this->setStatus($anna, RegistrationStatus::Registered);

        self::assertSame(['confirmed 2026-11-19 23:00:00 ledger_line'], $this->confirmations($anna));
    }

    public function testARefundOfAPayerStillAtItsTargetKeepsTheConfirmationAfterAnotherPayersLaterCharge(): void
    {
        $this->useComponents(new ShareComponent('3000'));
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '8000', '2026-11-20');
        $this->payOn($anna, '3000', '2026-11-21', 'congregation');
        self::assertSame('confirmed', $this->confirmationState($anna));

        // A later charge raises the congregation's part; the participant's surplus is refunded.
        $this->useComponents(new ShareComponent('4000'));
        $this->evaluate($anna);
        $this->refundOn($anna, '2000', '2026-11-28');

        self::assertSame('confirmed', $this->confirmationState($anna), 'The participant is still at its target.');
        self::assertCount(2, $this->confirmations($anna));
    }

    public function testAPaymentMovedAwayBelowTheThresholdUnconfirmsLikeARefund(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->moveAway($anna, '4000', '2026-11-28');

        self::assertSame('unconfirmed 2026-11-27 23:00:00 ledger_line', $this->confirmations($anna)[2]);
    }

    public function testARefundRecordedByANestedUseCaseUnconfirmsInTheOuterRun(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $refund = new AdminProbe(
            function (array $input, Context $context) use ($anna): void {
                $this->lockRegistrations($context, $anna);
                $this->ledger->record(
                    new NewLine(
                        $anna,
                        LineKind::ManualRefund,
                        'participant',
                        $this->huf('1000'),
                        new Subject('test_payment', null, 'p'),
                        'nested:1',
                        '2026-11-28',
                    ),
                    $context
                );
            }
        );

        $this->inRun(static function (array $input, Context $context) use ($refund): void {
            $context->run($refund, []);
        });

        self::assertSame('unconfirmed', $this->confirmationState($anna));
    }
}
