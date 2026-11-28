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
use Kalaka\Pricing\Api\ConfirmationThreshold;
use Kalaka\Pricing\Api\Confirmations;
use Kalaka\Pricing\Api\ConfirmationThresholdInput;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationKey;
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

    /**
     * The flag is set only on a registered registration (the owner's topic 1 ruling, H3), so both qualify
     * together only when a withdrawn registration with a set flag and payments is restored: the earlier counts.
     */
    public function testWithPaymentsAndTheFlagBothTheEarlierCounts(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->setFlag($anna);
        $bela = $this->register('00A2');
        $this->setFlag($bela);
        $this->payOn($bela, '10000', '2026-12-05');

        foreach ([$anna, $bela] as $key) {
            $this->setStatus($key, RegistrationStatus::Withdrawn);
            $this->setStatus($key, RegistrationStatus::Registered);
        }

        self::assertSame('confirmed 2026-11-19 23:00:00 ledger_line', $this->confirmations($anna)[2]);
        self::assertMatchesRegularExpression(
            '/^confirmed 2026-11-30 08:00:\d\d manual_confirmation$/',
            $this->confirmations($bela)[2]
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

    /** D14: the batch answers as `isConfirmed()` does for each registration. */
    public function testConfirmedOfMatchesIsConfirmedForABatch(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $bert = $this->register('00A2');
        $cili = $this->register('00A5');
        $dora = $this->register('00A8', RegistrationStatus::Waitlisted);
        $this->payOn($anna, '10000', '2026-11-20');
        $this->payOn($cili, '10000', '2026-11-20');
        $this->setStatus($cili, RegistrationStatus::Withdrawn);
        $unknown = new RegistrationKey($this->event, '00B0');
        $keys = [$anna, $bert, $cili, $dora, $unknown];

        $confirmations = new Confirmations();
        $batch = $confirmations->confirmedOf($this->event, $keys);

        self::assertSame(
            ['00A0' => true, '00A2' => false, '00A5' => false, '00A8' => false, '00B0' => false],
            $batch
        );
        foreach ($keys as $key) {
            self::assertSame($confirmations->isConfirmed($key), $batch[$key->code], $key->code);
        }
        self::assertSame([], $confirmations->confirmedOf($this->event, []));
        $this->expectException(\InvalidArgumentException::class);
        $confirmations->confirmedOf($this->event, [new RegistrationKey($this->event + 1, '00A0')]);
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

    /** The owner's ruling 2026-11-21: below the threshold the unconfirm stays, and a payment confirms again. */
    public function testTheUnconfirmBelowTheThresholdStaysAndAPaymentConfirmsAgain(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->refundOn($anna, '3000', '2026-11-25');
        self::assertSame('confirmed', $this->confirmationState($anna));
        $now = $this->utc('2026-12-01 08:00:00');
        self::assertTrue((new Confirmations())->isConfirmed($anna));
        self::assertNull((new ManualConfirmations())->reconfirmsOnUnconfirm($anna, $now), 'Below: no warning.');

        self::assertSame(['changed' => true], $this->unconfirm($anna, 'Teszt indok'));
        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertCount(2, $this->confirmations($anna), 'Below: one unconfirmed record, no second answer.');
        self::assertMatchesRegularExpression(
            '/^unconfirmed 2026-11-30 08:00:\d\d unconfirmation$/',
            $this->confirmations($anna)[1]
        );
        self::assertSame(['changed' => false], $this->unconfirm($anna), 'Not confirmed: nothing to do.');

        $this->payOn($anna, '3000', '2026-12-02');
        self::assertSame('confirmed', $this->confirmationState($anna), 'A payment reaching it confirms again.');
        self::assertSame('confirmed 2026-12-01 23:00:00 ledger_line', $this->confirmations($anna)[2]);
    }

    /** The owner's ruling 2026-11-21: at the threshold the unconfirm is allowed and confirms at once. */
    public function testTheUnconfirmAtTheThresholdRecordsUnconfirmedThenConfirmedInTheSamePass(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $confirmations = new ManualConfirmations();
        self::assertSame('payments', $confirmations->reconfirmsOnUnconfirm($anna, $this->utc('2026-12-01 08:00:00')));
        self::assertCount(1, $this->confirmations($anna), 'The warning read writes nothing.');
        self::assertSame([], $this->unconfirmationRows());

        self::assertSame(['changed' => true], $this->unconfirm($anna));

        self::assertSame('confirmed', $this->confirmationState($anna), 'The state the warning named.');
        $records = $this->confirmations($anna);
        self::assertCount(3, $records);
        self::assertMatchesRegularExpression('/^unconfirmed 2026-11-30 08:00:\d\d unconfirmation$/', $records[1]);
        // D2: the re-confirm is effective no earlier than the unconfirm, not at the payment's value date.
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d ledger_line$/', $records[2]);
        self::assertSame(explode(' ', $records[1])[2], explode(' ', $records[2])[2]);
        self::assertCount(1, $this->unconfirmationRows(), 'The unconfirm fact is kept.');

        $this->evaluate($anna);
        self::assertCount(3, $this->confirmations($anna), 'It converges.');
    }

    public function testTheUnconfirmWithTheFlagSetAtTheThresholdRevokesItAndConfirmsByThePayments(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->setFlag($anna);
        $this->payOn($anna, '10000', '2026-11-20');
        $now = $this->utc('2026-12-01 08:00:00');
        self::assertSame('payments', (new ManualConfirmations())->reconfirmsOnUnconfirm($anna, $now));

        self::assertSame(['changed' => true], $this->unconfirm($anna));

        self::assertNull((new ManualConfirmations())->of($anna), 'The flag is revoked.');
        $records = $this->confirmations($anna);
        self::assertCount(3, $records);
        self::assertStringStartsWith('unconfirmed ', $records[1]);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d ledger_line$/', $records[2]);
    }

    public function testTheUnconfirmOfANothingDueConfirmationConfirmsAgainAsNothingDue(): void
    {
        $anna = $this->register('00A0');
        self::assertSame('confirmed', $this->confirmationState($anna));
        $now = $this->utc('2026-12-01 08:00:00');
        self::assertSame('nothing_due', (new ManualConfirmations())->reconfirmsOnUnconfirm($anna, $now));

        self::assertSame(['changed' => true], $this->unconfirm($anna));

        $records = $this->confirmations($anna);
        self::assertCount(3, $records);
        self::assertStringStartsWith('unconfirmed ', $records[1]);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d registration$/', $records[2]);
        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    /** The flag revoked and the payments below: the dry run names nothing, and the act leaves it unconfirmed. */
    public function testTheWarningReadIsNullWhenNothingWouldConfirmAgainOrItIsNotConfirmed(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '2000', '2026-11-20');
        $confirmations = new ManualConfirmations();
        $now = $this->utc('2026-12-01 08:00:00');
        self::assertNull($confirmations->reconfirmsOnUnconfirm($anna, $now), 'Not confirmed.');

        $this->setFlag($anna);
        self::assertTrue((new Confirmations())->isConfirmed($anna));
        self::assertNull($confirmations->reconfirmsOnUnconfirm($anna, $now), 'The flag does not count.');

        $this->unconfirm($anna);
        self::assertSame('unconfirmed', $this->confirmationState($anna));
        self::assertFalse((new Confirmations())->isConfirmed($anna));
    }

    /** A retry after an at-once re-confirm is a new act: a second pair of records. */
    public function testARetryAfterAnAtOnceReconfirmWritesAgain(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');

        $this->unconfirm($anna, 'Első');
        self::assertSame(['changed' => true], $this->unconfirm($anna, 'Második'));

        $records = $this->confirmations($anna);
        self::assertCount(5, $records);
        self::assertSame(
            ['confirmed', 'unconfirmed', 'confirmed', 'unconfirmed', 'confirmed'],
            array_map(static fn (string $record): string => explode(' ', $record)[0], $records)
        );
        self::assertSame('Második', $this->unconfirmationRows()[0]['reason']);
    }

    /** The `<=` equality: a refund after an at-once re-confirm does not unconfirm. */
    public function testARefundAfterAnAtOnceReconfirmStaysConfirmed(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->unconfirm($anna);
        self::assertCount(3, $this->confirmations($anna));

        $this->refundOn($anna, '3000', '2026-11-30');
        $this->evaluate($anna);

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(3, $this->confirmations($anna));
    }

    /** D2: a late-entered payment whose value date is before the unconfirm confirms no earlier than it. */
    public function testAConfirmationAfterTheUnconfirmIsNeverEffectiveBeforeIt(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '10000', '2026-11-20');
        $this->refundOn($anna, '3000', '2026-11-25');
        $this->unconfirm($anna);
        $unconfirmed = $this->confirmations($anna)[1];

        $this->payOn($anna, '3000', '2026-11-22');

        $records = $this->confirmations($anna);
        self::assertCount(3, $records);
        self::assertMatchesRegularExpression('/^confirmed 2026-11-30 08:00:\d\d ledger_line$/', $records[2]);
        self::assertSame(explode(' ', $unconfirmed)[2], explode(' ', $records[2])[2]);
    }
}
