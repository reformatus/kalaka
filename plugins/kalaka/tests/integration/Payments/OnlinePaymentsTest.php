<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Payments\Api\OnlineItem;
use Kalaka\Payments\Api\OnlinePayments;
use Kalaka\Payments\Api\PaidRequest;
use Kalaka\Payments\Api\RefundedRequest;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Pricing\ConfirmationTestCase;

/** What a provider reports back: a paid request and a refund are recorded once, and never gated. */
final class OnlinePaymentsTest extends ConfirmationTestCase
{
    private Runner $production;

    private OnlinePayments $online;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->production = Runner::current();
        Runner::publish($this->runner);
        $this->online = new OnlinePayments();
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'kalaka_action_mails', [
            'event_id' => $this->event,
            'mail' => 'payment_recorded',
            'version' => 1,
            'enabled' => 1,
            'body_id' => null,
        ]);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);

        parent::tear_down();
    }

    private function item(RegistrationKey $key, string $amount, string $ref = '71'): OnlineItem
    {
        return new OnlineItem($key, 'participant', $this->huf($amount), $ref);
    }

    private function paid(string $order, OnlineItem ...$items): PaidRequest
    {
        return new PaidRequest('fake', $order, $this->utc('2026-11-30 09:30:00'), array_values($items));
    }

    /** @return list<array<string, ?string>> */
    private function lines(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_id = %d ORDER BY id',
                $wpdb->prefix . 'kalaka_ledger_lines',
                $this->event
            ),
            ARRAY_A
        );
        self::assertIsArray($rows);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['kind'] === 'payment' || $row['kind'] === 'refund'
        ));
    }

    private function mails(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_deliveries WHERE mail = 'payment_recorded'"
        );
    }

    public function testAPaidRequestWritesOnePaymentLinePerItem(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');

        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000', '71')));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('payment', $lines[0]['kind']);
        self::assertSame('participant', $lines[0]['payer']);
        self::assertSame('-10000.00000000', $lines[0]['amount']);
        self::assertSame('online:fake:order:501:71', $lines[0]['idempotency_key']);
        self::assertSame('fake_order', $lines[0]['source_type']);
        self::assertSame('501', $lines[0]['source_key']);
        self::assertSame('2026-11-30 09:30:00.000000', $lines[0]['paid_at']);
        self::assertSame('0', $this->balance($anna));
        self::assertSame(1, $this->mails());
    }

    public function testConfirmationFollowsThePayment(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        self::assertNotSame('confirmed', $this->confirmationState($anna));

        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));

        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    public function testAReplayWritesNothingMoreAndQueuesNoSecondMail(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $paid = $this->paid('501', $this->item($anna, '10000'));

        $this->online->recordPaid($paid);
        $this->online->recordPaid($paid);

        self::assertCount(1, $this->lines());
        self::assertSame(1, $this->mails());
    }

    public function testAPaidOrderForAWithdrawnRegistrationStillRecordsAndASurplusIsAnOverpayment(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0', RegistrationStatus::Withdrawn);

        $this->online->recordPaid($this->paid('501', $this->item($anna, '12000')));

        self::assertCount(1, $this->lines());
        self::assertStringStartsWith('-', $this->balance($anna));
    }

    public function testTwoRegistrationsInOneRequestEachGetTheirOwnLineAndMail(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $bela = $this->register('00A2');

        // Reported in the reverse of the lock order.
        $this->online->recordPaid(
            $this->paid('501', $this->item($bela, '10000', '72'), $this->item($anna, '10000', '71'))
        );

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertEqualsCanonicalizing(['00A0', '00A2'], array_column($lines, 'code'));
        self::assertEqualsCanonicalizing(
            ['online:fake:order:501:71', 'online:fake:order:501:72'],
            array_column($lines, 'idempotency_key')
        );
        self::assertSame(2, $this->mails());
    }

    public function testARefundWritesRefundLinesKeyedByTheRefund(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));
        $refund = new RefundedRequest(
            'fake',
            '501',
            '9',
            $this->utc('2026-12-01 10:00:00'),
            [$this->item($anna, '3000')]
        );

        $this->online->recordRefunded($refund);
        $this->online->recordRefunded($refund);

        // The payment's mail and the refund's, once each: the replay queued none.
        self::assertSame(2, $this->mails());
        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame('refund', $lines[1]['kind']);
        self::assertSame('3000.00000000', $lines[1]['amount']);
        self::assertSame('online:fake:refund:9:71', $lines[1]['idempotency_key']);
        self::assertSame('fake_order', $lines[1]['source_type']);
        self::assertSame('501', $lines[1]['source_key']);
        self::assertSame('2026-12-01 10:00:00.000000', $lines[1]['paid_at']);
        // A refund never unconfirms.
        self::assertSame('confirmed', $this->confirmationState($anna));
    }

    public function testARefundTheProviderAllocatedIsMarkedOnTheAuditEntry(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));
        $refund = new RefundedRequest(
            'fake',
            '501',
            '9',
            $this->utc('2026-12-01 10:00:00'),
            [$this->item($anna, '3000')],
            true
        );

        $this->online->recordRefunded($refund);

        self::assertSame(2, $this->mails());
        global $wpdb;
        $changes = (string) $wpdb->get_var(
            "SELECT GROUP_CONCAT(changes) FROM {$wpdb->prefix}kalaka_audit_entries"
            . " WHERE action = 'kalaka/record-online-refund'"
        );
        self::assertStringContainsString('"allocated":true', $changes);
    }

    public function testAReplayWithOtherContentIsAFailureNeverASecondLine(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));

        try {
            $this->online->recordPaid($this->paid('501', $this->item($anna, '9000')));
            self::fail('The conflicting replay was accepted.');
        } catch (SystemFailure) {
            self::assertCount(1, $this->lines());
        }
    }

    public function testAnItemOfAnUnknownRegistrationIsSkippedAndTheRestIsWritten(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $gone = new RegistrationKey($this->event, '00A5');

        $log = $this->capturedErrorLog(function () use ($gone, $anna): void {
            $this->online->recordPaid(
                $this->paid('501', $this->item($gone, '10000', '70'), $this->item($anna, '10000', '71'))
            );
        });

        self::assertStringContainsString('Recording an online payment failed', $log);
        self::assertStringContainsString('00A5', $log);

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('00A0', $lines[0]['code']);
    }

    public function testAnItemOfAnotherPayerFailsTheReportAndWritesNothing(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $paid = $this->paid(
            '501',
            $this->item($anna, '4000', '71'),
            new OnlineItem($anna, 'congregation', $this->huf('6000'), '72')
        );

        try {
            $this->online->recordPaid($paid);
            self::fail('The report was accepted.');
        } catch (SystemFailure) {
            self::assertSame([], $this->lines());
        }
    }

    public function testARefThatDiffersOnlyInCaseIsRefusedSoTheKeyStaysUnique(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->item($this->register('00A0'), '1000', 'AbC');
    }

    public function testAReportWithABadRefAndValidItemsIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaidRequest('fake', 'bad ref', $this->utc('2026-11-30 09:30:00'), [
            $this->item($this->register('00A0'), '1000'),
        ]);
    }

    public function testAReportOfNoItemsWithAValidRefIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaidRequest('fake', '501', $this->utc('2026-11-30 09:30:00'), []);
    }

    public function testTwoItemsOfOneReportWithTheSameItemRefAreRefused(): void
    {
        $anna = $this->register('00A0');

        $this->expectException(\InvalidArgumentException::class);
        $this->paid('501', $this->item($anna, '1000', '71'), $this->item($anna, '1000', '71'));
    }

    public function testARefundReplayWithOtherContentIsAFailureNeverASecondLine(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));
        $at = $this->utc('2026-12-01 10:00:00');
        $this->online->recordRefunded(new RefundedRequest('fake', '501', '9', $at, [$this->item($anna, '3000')]));

        try {
            $this->online->recordRefunded(new RefundedRequest('fake', '501', '9', $at, [$this->item($anna, '2000')]));
            self::fail('The conflicting replay was accepted.');
        } catch (SystemFailure) {
            self::assertCount(2, $this->lines());
            self::assertSame(2, $this->mails());
        }
    }

    public function testAClosedOrUnpublishedEventStillRecords(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $bela = $this->register('00A2');
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['state' => 'unpublished'], ['id' => $this->event]);

        $this->online->recordPaid($this->paid('501', $this->item($anna, '10000')));
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['state' => 'archived'], ['id' => $this->event]);
        $this->online->recordPaid($this->paid('502', $this->item($bela, '10000')));

        self::assertCount(2, $this->lines());
        self::assertSame(2, $this->mails());
    }
}
