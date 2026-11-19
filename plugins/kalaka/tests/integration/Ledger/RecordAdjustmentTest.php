<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\RecordAdjustment;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Api\RegistrationKey;

/** G5: the "record adjustment" use case: waiving the rest of a short transfer, with a reason. */
final class RecordAdjustmentTest extends ManualEntryTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function adjustmentInput(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->event,
            'code' => '00A0',
            'amount' => '-1000',
            'currency' => 'HUF',
            'reason' => 'Teszt bankköltség elengedése',
            'idempotency_key' => 'a1',
        ];
    }

    public function testItWritesOneAdjustmentLineAndItsReasonAsANote(): void
    {
        $id = $this->exec(new RecordAdjustment(), $this->adjustmentInput());

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame(['id' => (int) $lines[0]['id']], $id);
        self::assertSame('adjustment', $lines[0]['kind']);
        self::assertSame('-1000.00000000', $lines[0]['amount']);
        self::assertSame('participant', $lines[0]['payer']);
        self::assertSame('manual:a1', $lines[0]['idempotency_key']);
        self::assertNull($lines[0]['value_date']);
        self::assertNull($lines[0]['paid_at']);
        self::assertNull($lines[0]['method']);
        self::assertSame('0', $lines[0]['correction']);
        self::assertSame('wp_user', $lines[0]['source_type']);
        self::assertSame((string) $this->admin->userId, $lines[0]['source_key']);
        $notes = $this->notes();
        self::assertCount(1, $notes);
        self::assertSame('Teszt bankköltség elengedése', $notes[0]['note']);
        self::assertSame($lines[0]['id'], $notes[0]['line_id']);
    }

    public function testEitherSignIsAllowed(): void
    {
        $this->exec(new RecordAdjustment(), $this->adjustmentInput(['amount' => '250.5', 'idempotency_key' => 'a2']));

        self::assertSame('250.50000000', $this->lines()[0]['amount']);
    }

    public function testTheReasonIsRequired(): void
    {
        foreach ([null, '', '   '] as $position => $reason) {
            $input = $this->adjustmentInput(['idempotency_key' => 'r' . $position]);
            unset($input['reason']);
            if ($reason !== null) {
                $input['reason'] = $reason;
            }
            try {
                $this->exec(new RecordAdjustment(), $input);
                self::fail('An adjustment without a reason was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
                self::assertSame([], $this->notes());
            }
        }
    }

    public function testBadInputIsRefused(): void
    {
        $bad = [
            ['amount' => '0'],
            ['amount' => '-0.00'],
            ['currency' => 'EUR'],
            ['amount' => '-1.234'],
            ['payer' => 'x'],
        ];
        foreach ($bad as $position => $override) {
            try {
                $input = $this->adjustmentInput($override + ['idempotency_key' => 'b' . $position]);
                $this->exec(new RecordAdjustment(), $input);
                self::fail('Bad input was accepted.');
            } catch (InvalidInput) {
                self::assertSame([], $this->lines());
            }
        }
    }

    public function testTheSameKeyAndInputWritesOnce(): void
    {
        $first = $this->exec(new RecordAdjustment(), $this->adjustmentInput());
        $second = $this->exec(new RecordAdjustment(), $this->adjustmentInput());

        self::assertSame($first, $second);
        self::assertCount(1, $this->lines());
        self::assertCount(1, $this->notes());
    }

    public function testAReusedKeyWithOtherInputIsAConflict(): void
    {
        $this->exec(new RecordAdjustment(), $this->adjustmentInput());

        foreach ([['amount' => '-2000'], ['reason' => 'Más indok']] as $other) {
            try {
                $this->exec(new RecordAdjustment(), $this->adjustmentInput($other));
                self::fail('A reused key with other input was accepted.');
            } catch (IdempotencyConflict $conflict) {
                self::assertSame(409, $conflict->status());
                self::assertCount(1, $this->lines());
            }
        }
    }

    public function testTheSameKeyOnAPaymentIsAConflict(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['idempotency_key' => 'a1']));

        $this->expectException(IdempotencyConflict::class);
        $this->exec(new RecordAdjustment(), $this->adjustmentInput());
    }

    /** A short transfer and its waiver: what the payer owes is zero, and the payment facts stay what arrived. */
    public function testAWaiverOfAShortTransferBringsTheBalanceToZero(): void
    {
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '10000', 'currency' => 'HUF']);
        $this->exec(new RecordPayment(), $this->paymentInput(['amount' => '9000']));
        $key = new RegistrationKey($this->event, '00A0');
        self::assertSame('1000.00000000', (new Ledger())->balancesOf($key)->total(Currency::HUF)->amount());

        $this->exec(new RecordAdjustment(), $this->adjustmentInput());

        $ledger = new Ledger();
        self::assertSame('0.00000000', $ledger->balancesOf($key)->total(Currency::HUF)->amount());
        $owed = $ledger->summaryOf($key)->of('participant', Currency::HUF);
        self::assertSame('9000.00000000', $owed->charged->amount());
        self::assertSame('9000.00000000', $owed->paid->amount());
        self::assertSame('0.00000000', $owed->balance->amount());
        // The adjustment is not a payment fact: the facts are what arrived, and the evaluation added no line.
        self::assertCount(1, $ledger->paymentFactsOf($key));
        self::assertSame(['charge', 'manual_payment', 'adjustment'], array_column($this->lines(), 'kind'));
    }

    public function testTheAuditEntryHoldsWhatWasRecordedAndNoReason(): void
    {
        $this->exec(new RecordAdjustment(), $this->adjustmentInput(['reason' => 'Teszt titok']));

        $entry = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['action'] === 'kalaka/record-adjustment') {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('succeeded', $entry['outcome']);
        $changes = (string) $entry['changes'];
        self::assertStringContainsString('adjustment', $changes);
        self::assertStringContainsString('-1000', $changes);
        self::assertStringNotContainsString('titok', $changes);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id']));
    }

    public function testTheMoneyWriteActionCarriesItInsideItsViewAndTheRefundActionDoesNot(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);
        $this->exec(new RecordAdjustment(), $this->adjustmentInput(), new UserActor($user));
        self::assertCount(1, $this->lines());

        $other = $this->plainUser();
        $this->grantOn($other, ['kalaka_record_refund']);
        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordAdjustment(), $this->adjustmentInput(['idempotency_key' => 'a2']), new UserActor($other));
    }

    public function testNoGrantIsRefused(): void
    {
        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordAdjustment(), $this->adjustmentInput(), new UserActor($this->plainUser()));
    }

    public function testTheTouchRunsTheEvaluation(): void
    {
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
        self::assertSame([], $this->lines());

        $this->exec(new RecordAdjustment(), $this->adjustmentInput(['amount' => '-500']));

        self::assertContains('charge', array_column($this->lines(), 'kind'));
    }
}
