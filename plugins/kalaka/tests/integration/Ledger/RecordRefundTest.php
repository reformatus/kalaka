<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;

/** S22: the "record refund" use case (owner ruling 2026-11-18, point 5). */
final class RecordRefundTest extends ManualEntryTestCase
{
    public function testItRecordsOnePositiveManualRefundLine(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['method' => 'cash', 'note' => 'Teszt visszautalás']));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('manual_refund', $lines[0]['kind']);
        self::assertSame('5000.00000000', $lines[0]['amount']);
        self::assertSame('cash', $lines[0]['method']);
        self::assertSame('0', $lines[0]['correction']);
        self::assertSame('2026-11-28 23:00:00.000000', $lines[0]['value_date']);
        self::assertSame('Teszt visszautalás', $this->notes()[0]['note']);
    }

    public function testARefundWithoutANoteIsAllowed(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput());

        self::assertSame([], $this->notes());
    }

    public function testACorrectionNeedsItsReason(): void
    {
        foreach ([[], ['note' => '   ']] as $position => $note) {
            try {
                $this->exec(
                    new RecordRefund(),
                    $this->paymentInput(['correction' => true, 'idempotency_key' => 'c' . $position] + $note)
                );
                self::fail('A correction without a reason was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testACorrectionWithItsReasonIsMarkedAndNotLinked(): void
    {
        // An earlier line a correction could carry a link to.
        $this->exec(new RecordPayment(), $this->paymentInput(['idempotency_key' => 'p0']));
        $this->exec(new RecordRefund(), $this->paymentInput(['correction' => true, 'note' => 'Téves befizetés']));

        $lines = $this->lines();
        self::assertCount(2, $lines);
        $correction = $lines[1];
        self::assertSame('manual_refund', $correction['kind']);
        self::assertSame('1', $correction['correction']);
        self::assertSame('Téves befizetés', $this->notes()[0]['note']);
        // Its only reference is the recording user: no stored column names the line it corrects.
        self::assertSame('wp_user', $correction['source_type']);
        self::assertNull($correction['source_event_id']);
        self::assertSame((string) $this->admin->userId, $correction['source_key']);
    }

    /** C4-2: the refund replays like a payment: the same key and input write once. */
    public function testTheSameKeyAndInputWritesOnce(): void
    {
        $first = $this->exec(new RecordRefund(), $this->paymentInput(['note' => 'ref 1']));
        $second = $this->exec(new RecordRefund(), $this->paymentInput(['note' => 'ref 1']));

        self::assertSame($first, $second);
        self::assertCount(1, $this->lines());
        self::assertCount(1, $this->notes());
    }

    /** C4-2: the refund's audit entry holds what was recorded and never the note. */
    public function testTheAuditEntryHoldsWhatWasRecordedAndNoNote(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['method' => 'cash', 'note' => 'Teszt titok']));

        $entry = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['action'] === 'kalaka/record-refund') {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('succeeded', $entry['outcome']);
        $changes = (string) $entry['changes'];
        self::assertStringContainsString('manual_refund', $changes);
        self::assertStringContainsString('cash', $changes);
        self::assertStringNotContainsString('titok', $changes);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id']));
    }

    public function testTheSameKeyWithADifferentCorrectionFlagIsAnError(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['correction' => true, 'note' => 'ok']));

        $this->expectException(\Kalaka\Ledger\Api\IdempotencyConflict::class);
        $this->exec(new RecordRefund(), $this->paymentInput(['correction' => false, 'note' => 'ok']));
    }

    public function testThePaymentActionDoesNotCarryTheRefund(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);

        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordRefund(), $this->paymentInput(), new UserActor($user));
    }

    public function testTheRefundActionRecordsInsideItsView(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_refund']);

        $this->exec(new RecordRefund(), $this->paymentInput(), new UserActor($user));

        self::assertCount(1, $this->lines());
    }

    public function testARefundSharesTheAmountAndDateRules(): void
    {
        foreach ([['amount' => '0'], ['value_date' => '2026-12-05'], ['currency' => 'EUR']] as $bad) {
            try {
                $this->exec(new RecordRefund(), $this->paymentInput($bad));
                self::fail('Bad input was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }
}
