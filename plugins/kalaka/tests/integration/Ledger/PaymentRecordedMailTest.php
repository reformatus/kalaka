<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
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

    /** An online payment or refund: the act that records it decides the mail through the same one rule. */
    private function recordOnline(LineKind $kind, string $amount, string $key): int
    {
        $registration = new RegistrationKey($this->event, '00A0');
        $line = new NewLine(
            $registration,
            $kind,
            'participant',
            \Kalaka\Foundation\Api\Money\Money::of($amount, \Kalaka\Foundation\Api\Money\Currency::HUF),
            new Subject('gateway', null, 'g1'),
            'online:' . $key,
            null,
            new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC')),
        );
        $queued = $this->runner->run(
            new AdminProbe(function (array $input, Context $context) use ($registration, $line): bool {
                $this->lockRegistrations($context, $registration);

                return (new ActionMails())->lineRecorded($context, (new Ledger())->record($line, $context));
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

    public function testAReplayOfTheSameLineQueuesNothingMore(): void
    {
        $this->switchMail(true);

        $this->exec(new RecordPayment(), $this->paymentInput());
        $this->exec(new RecordPayment(), $this->paymentInput());

        self::assertCount(1, $this->queued());
        // The same online line again: its replay inserts nothing, so it queues nothing.
        $this->switchMail(true);
        $this->recordOnline(LineKind::Payment, '-700', 'z');
        self::assertSame(0, $this->recordOnline(LineKind::Payment, '-700', 'z'));
        self::assertCount(2, $this->queued());
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
