<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\RecordCharge;
use Kalaka\Ledger\RecordCorrection;
use Kalaka\Ledger\RecordDiscount;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * Owner topic 15, Q7: "payment recorded" is queued by the recording act for money in and money out (manual and
 * online, a payment or a refund), keyed by the line; never for a correction, a „Díj” or a „Kedvezmény”; per event on
 * or off.
 */
final class PaymentRecordedMailTest extends ManualEntryTestCase
{
    private function switchMail(bool $on): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'payment_recorded',
            'version' => 1,
            'enabled' => $on ? 1 : 0,
            'body_id' => null,
            'layout' => 1,
        ]);
    }

    /** @return list<array<string, ?string>> the "payment recorded" deliveries */
    private function queued(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}kalaka_deliveries WHERE mail = 'payment_recorded' ORDER BY id",
            ARRAY_A
        );
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    /**
     * An online payment or refund: the act that records it decides the mail through the same one rule. The line
     * is recorded for the payer given (`Ledger::record()` checks only that the payer ID is well formed); with
     * `$fail` the act fails after it decided the mail, so its whole transaction rolls back.
     */
    private function recordOnline(
        LineKind $kind,
        string $amount,
        string $key,
        string $payer = 'participant',
        bool $fail = false,
    ): int {
        $registration = new RegistrationKey($this->event, '00A0');
        $line = new NewLine(
            $registration,
            $kind,
            $payer,
            \Kalaka\Foundation\Api\Money\Money::of($amount, \Kalaka\Foundation\Api\Money\Currency::HUF),
            new Subject('gateway', null, 'g1'),
            'online:' . $key,
            null,
            new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC')),
        );
        $queued = $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($registration, $line, $fail): bool {
                $this->lockRegistrations($context, $registration);
                $queued = (new ActionMails())->lineRecorded($context, (new Ledger())->record($line, $context));
                if ($fail) {
                    throw new RuleViolation();
                }

                return $queued;
            }),
            [],
            $this->admin
        );
        self::assertIsBool($queued);

        return $queued ? 1 : 0;
    }

    public function testAManualPaymentAndAManualRefundQueueOneMailEachKeyedByTheirLine(): void
    {
        $this->switchMail(true);

        $payment = $this->exec(new RecordPayment(), $this->paymentInput());
        $refund = $this->exec(new RecordRefund(), $this->paymentInput(['idempotency_key' => 'k2', 'amount' => '1000']));

        $rows = $this->queued();
        self::assertCount(2, $rows);
        self::assertSame(['ledger_line', (string) $payment['id'], '00A0', 'action'], [
            $rows[0]['cause_type'], $rows[0]['cause_key'], $rows[0]['code'], $rows[0]['kind'],
        ]);
        self::assertSame((string) $refund['id'], $rows[1]['cause_key']);
    }

    public function testAnOnlinePaymentAndAnOnlineRefundQueueOneMailEach(): void
    {
        $this->switchMail(true);

        self::assertSame(1, $this->recordOnline(LineKind::Payment, '-5000', 'a'));
        self::assertSame(1, $this->recordOnline(LineKind::Refund, '2000', 'b'));

        self::assertCount(2, $this->queued());
    }

    public function testAReplayOrARetryOfALineQueuesNothingMore(): void
    {
        $this->switchMail(true);

        // By hand: the same payment, the same refund (a retry of the act with its key).
        $this->exec(new RecordPayment(), $this->paymentInput());
        $this->exec(new RecordPayment(), $this->paymentInput());
        $refund = $this->paymentInput(['idempotency_key' => 'r1', 'amount' => '1000']);
        $first = $this->exec(new RecordRefund(), $refund);
        self::assertSame($first, $this->exec(new RecordRefund(), $refund));
        self::assertCount(2, $this->lines());
        self::assertCount(2, $this->queued());

        // Online: the same line again inserts nothing, so it queues nothing.
        self::assertSame(1, $this->recordOnline(LineKind::Payment, '-700', 'z'));
        self::assertSame(0, $this->recordOnline(LineKind::Payment, '-700', 'z'));
        self::assertCount(3, $this->queued());
    }

    /** Without the "only a newly inserted line queues" guard, the replay below would queue a mail for an old line. */
    public function testAReplayAfterTheMailWasSwitchedOnQueuesNoneForTheOldLine(): void
    {
        $this->switchMail(false);
        self::assertSame(0, $this->recordOnline(LineKind::Payment, '-900', 'late'));
        self::assertSame([], $this->queued());

        $this->switchMail(true);
        self::assertSame(0, $this->recordOnline(LineKind::Payment, '-900', 'late'));

        self::assertSame([], $this->queued());
    }

    public function testARecordingThatRollsBackLeavesNeitherTheLineNorTheDelivery(): void
    {
        $this->switchMail(true);

        try {
            $this->recordOnline(LineKind::Payment, '-4000', 'gone', 'participant', true);
            self::fail('The act should have failed.');
        } catch (RuleViolation) {
            self::assertSame([], $this->queued());
            self::assertSame([], $this->lines());
        }

        // The same key again, now without the failure: the line and its mail are written together.
        self::assertSame(1, $this->recordOnline(LineKind::Payment, '-4000', 'gone'));
        self::assertCount(1, $this->lines());
        self::assertCount(1, $this->queued());
    }

    public function testALineOfAPayerOtherThanTheParticipantQueuesNone(): void
    {
        $this->switchMail(true);

        self::assertSame(0, $this->recordOnline(LineKind::Payment, '-20000', 'c1', 'congregation'));
        self::assertSame(0, $this->recordOnline(LineKind::Refund, '1000', 'c2', 'congregation'));
        self::assertCount(2, $this->lines());
        self::assertSame([], $this->queued());

        // The participant's own line still queues one.
        self::assertSame(1, $this->recordOnline(LineKind::Payment, '-500', 'p1'));
        self::assertCount(1, $this->queued());
    }

    public function testACorrectionOfEitherDirectionQueuesNone(): void
    {
        $this->switchMail(true);

        $this->exec(new RecordCorrection(), [
            'event_id' => $this->event, 'code' => '00A0', 'amount' => '1200', 'currency' => 'HUF',
            'direction' => 'in', 'reason' => 'Téves könyvelés', 'idempotency_key' => 'c1',
        ]);
        $this->exec(new RecordCorrection(), [
            'event_id' => $this->event, 'code' => '00A0', 'amount' => '200', 'currency' => 'HUF',
            'direction' => 'out', 'reason' => 'Téves könyvelés', 'idempotency_key' => 'c2',
        ]);

        self::assertCount(2, $this->lines());
        self::assertSame([], $this->queued());
    }

    public function testAManualChargeOrDiscountQueuesNoneEvenWhenItsPaymentIsTicked(): void
    {
        $this->switchMail(true);
        $fee = [
            'event_id' => $this->event, 'code' => '00A0', 'amount' => '3000', 'currency' => 'HUF',
            'title' => 'Teszt kirándulás', 'idempotency_key' => 'f1',
        ];
        $tick = ['completed' => true, 'method' => 'cash', 'value_date' => '2026-11-29'];

        $this->exec(new RecordCharge(), $fee);
        $this->exec(new RecordCharge(), $tick + ['idempotency_key' => 'f2'] + $fee);
        $this->exec(new RecordDiscount(), $tick + ['idempotency_key' => 'f3'] + $fee);

        self::assertSame([], $this->queued());
    }

    public function testAnEventWithTheMailOffQueuesNone(): void
    {
        $this->switchMail(false);
        $this->exec(new RecordPayment(), $this->paymentInput());
        self::assertSame([], $this->queued());

        $this->switchMail(true);
        $this->exec(new RecordPayment(), $this->paymentInput(['idempotency_key' => 'k2']));
        self::assertCount(1, $this->queued());
    }

    public function testNoRowAtAllIsTheDefaultOff(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput());

        self::assertSame([], $this->queued());
    }
}
