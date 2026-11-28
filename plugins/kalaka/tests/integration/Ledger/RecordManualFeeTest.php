<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\RecordCharge;
use Kalaka\Ledger\RecordCorrection;
use Kalaka\Ledger\RecordDiscount;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;
use Kalaka\Registrations\Api\RegistrationKey;

/** S22 topic 10: "record charge" and "record discount", the manual owed-side entries, and their completed pair. */
final class RecordManualFeeTest extends ManualEntryTestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function feeInput(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->event,
            'code' => '00A0',
            'amount' => '3000',
            'currency' => 'HUF',
            'title' => 'Teszt kirándulás',
            'idempotency_key' => 'f1',
        ];
    }

    /** @return array<string, mixed> */
    private function completed(string $comment = ''): array
    {
        return ['completed' => true, 'method' => 'cash', 'value_date' => '2026-11-29']
            + ($comment === '' ? [] : ['note' => $comment]);
    }

    /** @return list<array<string, ?string>> */
    private function fees(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE event_id = %d ORDER BY id',
                $wpdb->prefix . 'kalaka_manual_fees',
                $this->event
            ),
            ARRAY_A
        );
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    /** @return list<string> the kinds and amounts of the lines, oldest first */
    private function lineSummary(): array
    {
        return array_map(
            static fn (array $line): string => $line['kind'] . ' ' . rtrim(rtrim((string) $line['amount'], '0'), '.'),
            $this->lines()
        );
    }

    /** @return array<string, ?string> the one audit entry of the use case */
    private function auditOf(string $action): array
    {
        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
        self::assertCount(1, $entries);

        return $entries[0];
    }

    public function testAChargeIsAFactAndTheEvaluationWritesItsOwnLine(): void
    {
        $result = $this->exec(new RecordCharge(), $this->feeInput());

        $fees = $this->fees();
        self::assertCount(1, $fees);
        self::assertSame(['id' => (int) $fees[0]['id']], $result);
        self::assertSame('charge', $fees[0]['type']);
        self::assertSame('3000.00000000', $fees[0]['amount']);
        self::assertSame('Teszt kirándulás', $fees[0]['title']);
        self::assertSame('manual_fee:f1', $fees[0]['idempotency_key']);
        self::assertSame((string) $this->admin->userId, $fees[0]['recorded_by']);

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('charge', $lines[0]['kind']);
        self::assertSame('3000.00000000', $lines[0]['amount']);
        self::assertSame('fee_component', $lines[0]['source_type']);
        self::assertSame('manual:' . $fees[0]['id'], $lines[0]['source_key']);
        self::assertSame('3000.00000000', (new Ledger())->balancesOf(new RegistrationKey($this->event, '00A0'))
            ->of('participant', \Kalaka\Foundation\Api\Money\Currency::HUF)->amount());
    }

    public function testADiscountComposesMinusItsAmount(): void
    {
        $this->exec(new RecordDiscount(), $this->feeInput(['amount' => '500']));

        self::assertSame('discount', $this->fees()[0]['type']);
        self::assertSame('500.00000000', $this->fees()[0]['amount']);
        self::assertSame(['charge -500'], $this->lineSummary());
    }

    public function testEachEntryIsItsOwnLine(): void
    {
        $this->exec(new RecordCharge(), $this->feeInput());
        $this->exec(new RecordCharge(), $this->feeInput(['idempotency_key' => 'f2', 'title' => 'Másik']));
        $this->exec(new RecordDiscount(), $this->feeInput(['idempotency_key' => 'f3', 'amount' => '1000']));

        self::assertSame(['charge 3000', 'charge 3000', 'charge -1000'], $this->lineSummary());
        self::assertCount(3, array_unique(array_column($this->lines(), 'source_key')));
    }

    /** @return array<string, array<string>> */
    public static function otherStatuses(): array
    {
        return ['waitlisted' => ['waitlisted'], 'withdrawn' => ['withdrawn'], 'removed' => ['removed']];
    }

    /** S1-c: only a registered registration owes a fee, so only it can be charged or discounted. */
    public function testAnEntryOnAnotherStatusIsRefusedAndWritesNothing(): void
    {
        foreach (['waitlisted', 'withdrawn', 'removed'] as $position => $status) {
            $code = '00B' . $position;
            $this->addRegistration($this->event, $code, $status);
            foreach ([new RecordCharge(), new RecordDiscount()] as $useCase) {
                try {
                    $this->exec($useCase, $this->feeInput(['code' => $code]));
                    self::fail('An entry on a ' . $status . ' registration was accepted.');
                } catch (InvalidInput) {
                    self::assertSame([], $this->fees());
                    self::assertSame([], $this->lines());
                }
            }
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badInputs(): array
    {
        return [
            'a zero amount' => [['amount' => '0']],
            'too many decimals' => [['amount' => '10.123']],
            'another currency' => [['currency' => 'EUR']],
            'a blank title' => [['title' => '   ']],
            'a title of 192 characters' => [['title' => str_repeat('á', 192)]],
            'an undeclared payer' => [['payer' => 'congregation']],
            'a method without the tick' => [['method' => 'cash']],
            'a day without the tick' => [['value_date' => '2026-11-29']],
            'a comment without the tick' => [['note' => 'x']],
            'the tick without a method' => [['completed' => true, 'value_date' => '2026-11-29']],
            'the tick without a day' => [['completed' => true, 'method' => 'cash']],
            'the tick with a future day' => [['completed' => true, 'method' => 'cash', 'value_date' => '2026-12-01']],
            'a key with a trailing newline' => [['idempotency_key' => "f1\n"]],
        ];
    }

    /**
     * @dataProvider badInputs
     * @param array<string, mixed> $bad
     */
    public function testBadInputIsInvalidAndWritesNothing(array $bad): void
    {
        try {
            $this->exec(new RecordCharge(), $bad + $this->feeInput());
            self::fail('Bad input was accepted.');
        } catch (InvalidInput) {
            self::assertSame([], $this->fees());
            self::assertSame([], $this->lines());
        }
    }

    public function testTheTitleIsRequiredByTheSchema(): void
    {
        $input = $this->feeInput();
        unset($input['title']);
        $schema = (new RecordCharge())->inputSchema();

        self::assertContains('title', $schema['required']);
        self::assertNotContains('method', $schema['required']);
        self::assertNotContains('completed', $schema['required']);
        $this->expectException(InvalidInput::class);
        $this->exec(new RecordCharge(), $input);
    }

    public function testTheSameKeyAndInputWritesOnceAndAnotherInputIsAConflict(): void
    {
        $first = $this->exec(new RecordCharge(), $this->feeInput());
        $second = $this->exec(new RecordCharge(), $this->feeInput());

        self::assertSame($first, $second);
        self::assertCount(1, $this->fees());
        self::assertCount(1, $this->lines());

        foreach ([['amount' => '3001'], ['title' => 'Más'], ['amount' => '1']] as $different) {
            try {
                $this->exec(new RecordCharge(), $different + $this->feeInput());
                self::fail('A different entry under the same key was accepted.');
            } catch (IdempotencyConflict $conflict) {
                self::assertSame(409, $conflict->status());
            }
        }
        // The other type under the same key is a different entry too.
        try {
            $this->exec(new RecordDiscount(), $this->feeInput());
            self::fail('A discount replayed a charge.');
        } catch (IdempotencyConflict) {
            self::assertCount(1, $this->fees());
        }
        self::assertCount(1, $this->lines());
    }

    public function testAReplayAfterTheRegistrationLeftIsStillAReplay(): void
    {
        $first = $this->exec(new RecordCharge(), $this->feeInput());
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['status' => 'withdrawn'],
            ['event_id' => $this->event, 'code' => '00A0']
        );
        \Kalaka\Foundation\ObjectCache::clear();

        self::assertSame($first, $this->exec(new RecordCharge(), $this->feeInput()));
        self::assertCount(1, $this->fees());
    }

    public function testTheAuditHasOneRowAndNeverTheTitle(): void
    {
        $this->exec(new RecordCharge(), $this->feeInput(['title' => 'Teszt titok']));

        $entry = $this->auditOf('kalaka/record-charge');
        self::assertStringNotContainsString('Teszt titok', (string) $entry['changes']);
        self::assertStringContainsString('"charge"', (string) $entry['changes']);
    }

    public function testACompletedChargeWritesTheFactTheChargeAndThePaymentInOneRun(): void
    {
        $result = $this->exec(new RecordCharge(), $this->feeInput() + $this->completed('Teszt közlemény'));

        $fees = $this->fees();
        self::assertSame((int) $fees[0]['id'], $result['id']);
        $lines = $this->lines();
        self::assertSame(['manual_payment -3000', 'charge 3000'], array_map(
            static fn (array $line): string => $line['kind'] . ' ' . rtrim(rtrim((string) $line['amount'], '0'), '.'),
            [$lines[0], $lines[1]]
        ));
        self::assertSame((int) $lines[0]['id'], $result['line']);
        self::assertSame('manual_fee:f1:paid', $lines[0]['idempotency_key']);
        self::assertSame('cash', $lines[0]['method']);
        self::assertSame('0', $lines[0]['correction']);
        self::assertSame('2026-11-28 23:00:00.000000', $lines[0]['value_date']);
        self::assertSame('Teszt közlemény', $this->notes()[0]['note']);
        // The tick leaves the balance where it was.
        self::assertSame('0.00000000', (new Ledger())->balancesOf(new RegistrationKey($this->event, '00A0'))
            ->of('participant', \Kalaka\Foundation\Api\Money\Currency::HUF)->amount());

        // One audit row: the paid line's change, and neither the title nor the comment.
        $entry = $this->auditOf('kalaka/record-charge');
        self::assertStringNotContainsString('Teszt kirándulás', (string) $entry['changes']);
        self::assertStringNotContainsString('Teszt közlemény', (string) $entry['changes']);
        $fields = array_column($this->decode($entry['changes']), 'field');
        self::assertContains('manual_payment', $fields);
    }

    public function testACompletedDiscountWritesTheRefundAndTheNegativeCharge(): void
    {
        $this->exec(new RecordDiscount(), $this->feeInput(['amount' => '500']) + $this->completed());

        self::assertSame(['manual_refund 500', 'charge -500'], $this->lineSummary());
        self::assertSame([], $this->notes());
        self::assertSame('0', $this->lines()[0]['correction']);
    }

    public function testACompletedReplayWritesNothingAndATickChangeIsAConflict(): void
    {
        $first = $this->exec(new RecordCharge(), $this->feeInput() + $this->completed('közlemény'));
        $replay = $this->exec(new RecordCharge(), $this->feeInput() + $this->completed('közlemény'));

        self::assertSame($first, $replay);
        self::assertCount(1, $this->fees());
        self::assertCount(2, $this->lines());
        self::assertCount(1, $this->notes());

        $conflicts = [
            'the tick dropped' => $this->feeInput(),
            'another method' => $this->feeInput() + ['completed' => true, 'method' => 'bank_transfer',
                'value_date' => '2026-11-29', 'note' => 'közlemény'],
            'another day' => $this->feeInput() + ['completed' => true, 'method' => 'cash',
                'value_date' => '2026-11-28', 'note' => 'közlemény'],
            'another comment' => $this->feeInput() + $this->completed('más'),
        ];
        foreach ($conflicts as $name => $input) {
            try {
                $this->exec(new RecordCharge(), $input);
                self::fail('A conflict was accepted: ' . $name);
            } catch (IdempotencyConflict) {
                self::assertCount(2, $this->lines(), $name);
            }
        }

        // And the other way: an entry recorded plain cannot be replayed ticked.
        $this->exec(new RecordCharge(), $this->feeInput(['idempotency_key' => 'f2']));
        $this->expectException(IdempotencyConflict::class);
        $this->exec(new RecordCharge(), $this->feeInput(['idempotency_key' => 'f2']) + $this->completed());
    }

    /** A1: one grant action carries the five abilities, and the view action carries none of them. */
    public function testTheMoneyActionCarriesAllFiveAbilitiesAndTheViewActionNone(): void
    {
        $writer = $this->plainUser();
        $this->grantOn($writer, ['kalaka_record_money']);
        $viewer = $this->plainUser();
        $this->grantOn($viewer, ['kalaka_view_money']);

        $correction = [
            'event_id' => $this->event,
            'code' => '00A0',
            'amount' => '100',
            'currency' => 'HUF',
            'direction' => 'in',
            'reason' => 'Teszt',
            'idempotency_key' => 'u3',
        ];
        $uses = [
            [new RecordPayment(), $this->paymentInput(['idempotency_key' => 'u1'])],
            [new RecordRefund(), $this->paymentInput(['idempotency_key' => 'u2'])],
            [new RecordCorrection(), $correction],
            [new RecordCharge(), $this->feeInput(['idempotency_key' => 'u4'])],
            [new RecordDiscount(), $this->feeInput(['idempotency_key' => 'u5'])],
        ];
        foreach ($uses as [$useCase, $input]) {
            self::assertSame(Ledger::RECORD_MONEY, $useCase->action());
            try {
                $this->exec($useCase, $input, new UserActor($viewer));
                self::fail('The view action recorded: ' . $useCase::name());
            } catch (PermissionDenied) {
                self::assertSame([], $this->lines());
            }
        }
        foreach ($uses as [$useCase, $input]) {
            $this->exec($useCase, $input, new UserActor($writer));
        }
        // A payment, a refund, a correction, a charge and a discount, and the two lines the fees composed.
        self::assertSame(
            ['manual_payment', 'manual_refund', 'manual_payment', 'charge', 'charge'],
            array_column($this->lines(), 'kind')
        );
    }
}
