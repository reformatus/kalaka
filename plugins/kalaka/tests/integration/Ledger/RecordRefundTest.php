<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
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
        $this->exec(new RecordRefund(), $this->paymentInput(['correction' => true, 'note' => 'Téves befizetés']));

        $lines = $this->lines();
        self::assertSame('1', $lines[0]['correction']);
        self::assertSame('Téves befizetés', $this->notes()[0]['note']);
        self::assertArrayNotHasKey('corrects', $lines[0]);
    }

    public function testTheSameKeyWithADifferentCorrectionFlagIsAnError(): void
    {
        $this->exec(new RecordRefund(), $this->paymentInput(['correction' => true, 'note' => 'ok']));

        $this->expectException(\Kalaka\Foundation\Api\Error\SystemFailure::class);
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
