<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\RecordCorrection;
use Kalaka\Registrations\Api\RegistrationKey;

/** S22: the "record correction" use case (the owner's ruling 2026-11-19). */
final class RecordCorrectionTest extends ManualEntryTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function correction(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->event,
            'code' => '00A0',
            'amount' => '1200',
            'currency' => 'HUF',
            'direction' => 'in',
            'reason' => 'Téves könyvelés',
            'idempotency_key' => 'c1',
        ];
    }

    public function testACorrectionInIsAFlaggedNegativePaymentRowWithoutMethodOrDate(): void
    {
        $this->exec(new RecordCorrection(), $this->correction());

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('manual_payment', $lines[0]['kind']);
        self::assertSame('-1200.00000000', $lines[0]['amount']);
        self::assertSame('1', $lines[0]['correction']);
        self::assertNull($lines[0]['method']);
        // No date of its own: it counts from the time it was recorded (the clock: 2026-11-30 10:00, Budapest).
        self::assertSame('2026-11-30 09:00:00.000000', $lines[0]['value_date']);
        self::assertSame($lines[0]['recorded_at'], $lines[0]['value_date']);
        self::assertNull($lines[0]['paid_at']);
        self::assertSame('Téves könyvelés', $this->notes()[0]['note']);
    }

    public function testACorrectionOutIsAFlaggedPositiveRefundRow(): void
    {
        $this->exec(new RecordCorrection(), $this->correction(['direction' => 'out']));

        $lines = $this->lines();
        self::assertSame('manual_refund', $lines[0]['kind']);
        self::assertSame('1200.00000000', $lines[0]['amount']);
        self::assertSame('1', $lines[0]['correction']);
        self::assertNull($lines[0]['method']);
        self::assertSame($lines[0]['recorded_at'], $lines[0]['value_date']);
    }

    public function testACorrectionIsAPaymentFactAndCountsTowardThePaidAmount(): void
    {
        $this->exec(new RecordCorrection(), $this->correction());
        $this->exec(
            new RecordCorrection(),
            $this->correction(['direction' => 'out', 'amount' => '200', 'idempotency_key' => 'c2'])
        );

        $key = new RegistrationKey($this->event, '00A0');
        $facts = (new Ledger())->paymentFactsOf($key);
        self::assertCount(2, $facts);
        self::assertSame([LineKind::ManualPayment, LineKind::ManualRefund], array_map(
            static fn ($fact) => $fact->kind,
            $facts
        ));
        $summary = (new Ledger())->summaryOf($key);
        $sum = $summary->of('participant', \Kalaka\Foundation\Api\Money\Currency::HUF);
        self::assertSame('1000.00000000', $sum->paid->amount());
    }

    public function testTheReasonIsOptional(): void
    {
        foreach ([['reason' => '   '], ['reason' => '']] as $position => $override) {
            $this->exec(new RecordCorrection(), $this->correction($override + ['idempotency_key' => 'r' . $position]));
        }
        $input = $this->correction(['idempotency_key' => 'r2']);
        unset($input['reason']);
        $this->exec(new RecordCorrection(), $input);

        self::assertCount(3, $this->lines());
        self::assertSame([], $this->notes());
    }

    public function testNoMethodAndNoValueDateAreTaken(): void
    {
        foreach (['method' => 'cash', 'value_date' => '2026-11-29'] as $name => $value) {
            try {
                $this->exec(new RecordCorrection(), $this->correction([$name => $value]));
                self::fail('A correction took ' . $name . '.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testTheAmountIsPositiveAndTheDirectionIsRequired(): void
    {
        foreach ([['amount' => '0'], ['amount' => '-5'], ['direction' => 'sideways']] as $position => $override) {
            try {
                $input = $this->correction($override + ['idempotency_key' => 'a' . $position]);
                $this->exec(new RecordCorrection(), $input);
                self::fail('A bad correction was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testTheSameKeyAndInputWritesOnceAndAnotherInputIsAConflict(): void
    {
        $first = $this->exec(new RecordCorrection(), $this->correction());
        $second = $this->exec(new RecordCorrection(), $this->correction());
        self::assertSame($first, $second);
        self::assertCount(1, $this->lines());

        $this->expectException(IdempotencyConflict::class);
        $this->exec(new RecordCorrection(), $this->correction(['direction' => 'out']));
    }

    public function testAKeyWithATrailingNewlineIsInvalidInput(): void
    {
        $this->expectException(InvalidInput::class);
        $this->exec(new RecordCorrection(), $this->correction(['idempotency_key' => "c1\n"]));
    }

    public function testItIsMoneyWriteAndTheRefundActionDoesNotCarryIt(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_view_money']);

        try {
            $this->exec(new RecordCorrection(), $this->correction(), new UserActor($user));
            self::fail('The refund action recorded a correction.');
        } catch (PermissionDenied) {
            self::assertSame([], $this->lines());
        }

        $writer = $this->plainUser();
        $this->grantOn($writer, ['kalaka_record_money']);
        $this->exec(new RecordCorrection(), $this->correction(), new UserActor($writer));
        self::assertCount(1, $this->lines());
    }

    public function testTheAuditHoldsWhatWasRecordedAndNoReason(): void
    {
        $this->exec(new RecordCorrection(), $this->correction(['reason' => 'Teszt titok']));

        $changes = '';
        foreach ($this->entries() as $entry) {
            if ($entry['action'] === 'kalaka/record-correction') {
                $changes = (string) $entry['changes'];
            }
        }
        self::assertStringContainsString('manual_payment', $changes);
        self::assertStringNotContainsString('titok', $changes);
    }
}
