<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\RecordCharge;
use Kalaka\Ledger\RecordDiscount;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Pricing\Api\ConfirmationThreshold;
use Kalaka\Pricing\Api\ConfirmationThresholdInput;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * money.md, "Confirmation": the per-payer threshold, the transitions table, the effective time, "a later
 * charge or a higher threshold changes nothing", refunds that never unconfirm, staff's unconfirm and withdrawal
 * closing the sequence (the owner's rulings 2026-11-19). Value dates are days in Europe/Budapest, stored as
 * 00:00 there: 2026-11-20 is 2026-11-19 23:00 UTC.
 */
final class ConfirmationTest extends ConfirmationTestCase
{
    public function testOnePayerPartialThenExactConfirmsAtTheValueDateOfThePaymentThatReachedIt(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        self::assertSame([], $this->confirmations($anna), 'No first record: unconfirmed is the base state.');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->payOn($anna, '4000', '2026-11-20');
        self::assertSame([], $this->confirmations($anna));
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->payOn($anna, '6000', '2026-11-25');
        self::assertSame(['confirmed 2026-11-24 23:00:00 ledger_line'], $this->confirmations($anna));
    }

    public function testAnOverpaymentConfirmsAtThatPayment(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->payOn($anna, '15000', '2026-11-21');

        self::assertSame(['confirmed 2026-11-20 23:00:00 ledger_line'], $this->confirmations($anna));
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

        self::assertSame(['confirmed 2026-11-29 17:45:12 ledger_line'], $this->confirmations($anna));
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
            ['confirmed 2026-11-24 23:00:00 ledger_line'],
            $this->confirmations($anna),
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

        self::assertSame(['confirmed 2026-11-22 23:00:00 ledger_line'], $this->confirmations($anna));
    }

    public function testTheFlagConfirmsBelowAtItsSetTimeAndTheUnconfirmRevokesItAndUnconfirms(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '2000', '2026-11-20');

        $this->setFlag($anna);
        $records = $this->confirmations($anna);
        self::assertCount(1, $records);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d manual_confirmation$/', $records[0]);

        self::assertSame(['changed' => true], $this->unconfirm($anna));
        $records = $this->confirmations($anna);
        self::assertCount(2, $records);
        self::assertMatchesRegularExpression('/^unconfirmed 2026-11-30 08:00:\d\d unconfirmation$/', $records[1]);
        self::assertNull((new ManualConfirmations())->of($anna), 'The unconfirm revoked the flag.');

        $this->evaluate($anna);
        self::assertCount(2, $this->confirmations($anna), 'It converges: the next evaluation writes nothing.');
    }

    public function testTheFlagAboveTheThresholdChangesNothing(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $before = $this->confirmations($anna);

        $this->setFlag($anna);

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

    /** The owner's ruling 2026-11-19: a refund never unconfirms. */
    public function testARefundBelowTheThresholdKeepsTheConfirmation(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->refundOn($anna, '1000', '2026-11-28');
        $this->evaluate($anna);

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->confirmations($anna));
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
        self::assertCount(1, $this->confirmations($anna));
    }

    /** The owner's ruling 2026-11-19: neither a later charge nor a refund after it unconfirms. */
    public function testAfterALaterChargeARefundStillKeepsTheConfirmation(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setPrice('15000');
        self::assertSame('confirmed', $this->confirmationState($anna));

        $this->refundOn($anna, '500', '2026-11-28');

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->confirmations($anna));
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
        self::assertSame(['confirmed 2026-11-19 23:00:00 ledger_line'], $this->confirmations($anna));

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
        self::assertSame('unconfirmed', $this->lastRecordState($anna), 'The close.');
        self::assertNull($this->confirmationState($anna), 'Only a registered registration has a state.');
        self::assertCount(2, $this->confirmations($anna));

        $this->setStatus($anna, RegistrationStatus::Registered);
        $records = $this->confirmations($anna);
        self::assertCount(3, $records, 'A new record; the old one is never revived.');
        self::assertSame('confirmed 2026-11-19 23:00:00 ledger_line', $records[2]);
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
        self::assertCount(2, $this->confirmations($anna));
    }

    public function testAnUnconfirmedRegistrationThatWithdrawsGetsNoRecordAndNoState(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->setStatus($anna, RegistrationStatus::Withdrawn);

        self::assertSame([], $this->confirmations($anna));
        self::assertNull($this->confirmationState($anna));
    }

    public function testAWaitlistedRegistrationGetsNoDecisionAndAnAdmissionDecides(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0', RegistrationStatus::Waitlisted);
        $this->payOn($anna, '10000', '2026-11-20');
        self::assertSame([], $this->confirmations($anna));
        self::assertNull($this->confirmationState($anna), 'A waitlisted registration has no state.');

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
        self::assertCount(1, $this->confirmations($anna));
    }

    public function testARefundRecordedByANestedUseCaseKeepsTheConfirmation(): void
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

        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    /** #209 makes a correction a payment fact: one that reaches the threshold confirms, at its recording. */
    public function testACorrectionReachingTheThresholdConfirms(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '9000', '2026-11-20');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->correct($anna, '1000');

        $records = $this->confirmations($anna);
        self::assertCount(1, $records);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d ledger_line$/', $records[0]);
    }

    /**
     * @param array<string, mixed> $more
     */
    private function recordFee(
        \Kalaka\Registrations\Api\RegistrationKey $key,
        bool $discount,
        string $amount,
        string $id,
        array $more = [],
    ): void {
        $this->runner->run(
            $discount ? new RecordDiscount() : new RecordCharge(),
            [
                'event_id' => $key->eventId,
                'code' => $key->code,
                'amount' => $amount,
                'currency' => 'HUF',
                'title' => 'Teszt tétel',
                'idempotency_key' => $id,
            ] + $more,
            new CliActor(null)
        );
    }

    /**
     * money.md, "Confirmation": a payer's target is what the composition says it owes now, manual charges and
     * discounts included. 30 000 owed, 29 500 paid, then a 500 discount: the payment reaches the lowered target
     * and the effective time is that payment's value date (the owner's topic 10 ruling, Q7).
     */
    public function testADiscountThatLowersTheTargetConfirmsAtThePaymentsValueDate(): void
    {
        $this->setPrice('30000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '29500', '2026-11-20');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $this->recordFee($anna, true, '500', 'd1');

        self::assertSame(['confirmed 2026-11-19 23:00:00 ledger_line'], $this->confirmations($anna));
    }

    /** A charge after confirmation changes nothing (the existing rule). */
    public function testAManualChargeAfterConfirmationChangesNothing(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $records = $this->confirmations($anna);
        self::assertCount(1, $records);

        $this->recordFee($anna, false, '3000', 'c1');

        self::assertSame($records, $this->confirmations($anna));
        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertSame('3000', $this->balance($anna));
    }

    /** A charge marked as completed raises the target and the payments by the same amount: the shortfall stays. */
    public function testACompletedChargeLeavesTheShortfallWhereItWas(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '4000', '2026-11-20');

        $paid = ['completed' => true, 'method' => 'cash', 'value_date' => '2026-11-22'];
        $this->recordFee($anna, false, '3000', 'c1', $paid);

        self::assertSame([], $this->confirmations($anna));
        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertSame('6000', $this->balance($anna));
    }

    /** A discount that takes the target to zero confirms as "nothing due", completed or not. */
    public function testADiscountThatTakesTheTargetToZeroConfirmsAsNothingDue(): void
    {
        $this->setPrice('1000');
        $anna = $this->register('00A0');
        self::assertSame('unconfirmed', $this->confirmationState($anna));

        $paid = ['completed' => true, 'method' => 'cash', 'value_date' => '2026-11-22'];
        $this->recordFee($anna, true, '1000', 'd1', $paid);

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->confirmations($anna));
    }

    /** The owner's ruling 2026-11-19: staff may unconfirm only while the payments are below the threshold. */
    public function testTheUnconfirmIsAllowedBelowTheThresholdAndAPaymentConfirmsAgain(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->refundOn($anna, '3000', '2026-11-25');
        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertTrue((new ManualConfirmations())->canUnconfirm($anna, $this->utc('2026-12-01 08:00:00')));

        self::assertSame(['changed' => true], $this->unconfirm($anna, 'Teszt indok'));
        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertMatchesRegularExpression(
            '/^unconfirmed 2026-11-30 08:00:\d\d unconfirmation$/',
            $this->confirmations($anna)[1]
        );
        self::assertSame(['changed' => false], $this->unconfirm($anna), 'Not confirmed: nothing to do.');

        $this->payOn($anna, '3000', '2026-12-02');
        self::assertSame('confirmed', $this->confirmationState($anna), 'A payment reaching it confirms again.');
        self::assertSame('confirmed 2026-12-01 23:00:00 ledger_line', $this->confirmations($anna)[2]);
    }

    public function testTheUnconfirmIsRefusedAtTheThreshold(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        self::assertFalse((new ManualConfirmations())->canUnconfirm($anna, $this->utc('2026-12-01 08:00:00')));

        try {
            $this->unconfirm($anna);
            self::fail('The unconfirm was not refused.');
        } catch (RuleViolation $refused) {
            self::assertSame(422, $refused->status());
        }
        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $this->confirmations($anna));
    }

    /** H2: with the flag set and the payments at the threshold, the unconfirm is refused and the flag stays. */
    public function testTheUnconfirmIsRefusedAtTheThresholdWithTheFlagSet(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->setFlag($anna);
        $this->payOn($anna, '10000', '2026-11-20');

        $this->expectException(RuleViolation::class);
        try {
            $this->unconfirm($anna);
        } finally {
            self::assertNotNull((new ManualConfirmations())->of($anna));
            self::assertSame('confirmed', $this->confirmationState($anna));
        }
    }

    public function testANothingDueConfirmationCannotBeUnconfirmed(): void
    {
        $anna = $this->register('00A0');
        self::assertSame('confirmed', $this->confirmationState($anna));

        // A later charge takes the payments below; the confirmation was still because nothing was due.
        $this->setPrice('10000');
        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertFalse((new ManualConfirmations())->canUnconfirm($anna, $this->utc('2026-12-01 08:00:00')));

        $this->expectException(RuleViolation::class);
        $this->unconfirm($anna);
    }
}
