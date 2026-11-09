<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\LedgerLine;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\NewLine;
use Kalaka\Ledger\LedgerLineRepository;

/** AC2, AC3 and AC4: what `record()` writes, repeats and refuses. */
final class RecordLineTest extends LedgerTestCase
{
    public function testAChargeIsStoredWithItsValuesAndMarksTheRegistrationTouched(): void
    {
        $registration = $this->registration();
        $touched = null;
        $line = new NewLine(
            $registration,
            LineKind::Charge,
            'participant',
            $this->money('48000'),
            new Subject('fee_component', null, 'fixed_price'),
            'charge:fixed_price:participant:1'
        );

        $stored = $this->inRun(function (array $input, Context $context) use ($line, &$touched): LedgerLine {
            $this->lockRegistrations($context, $line->registration);
            $stored = $this->ledger->record($line, $context);
            $touched = $context->touchedRegistrations();

            return $stored;
        });

        self::assertInstanceOf(LedgerLine::class, $stored);
        self::assertGreaterThan(0, $stored->id);
        self::assertSame(LineKind::Charge, $stored->kind);
        self::assertSame('participant', $stored->payer);
        self::assertTrue($stored->amount->isEqualTo($this->money('48000')));
        self::assertSame('fee_component', $stored->source->type);
        self::assertNull($stored->source->eventId);
        self::assertSame('fixed_price', $stored->source->key);
        self::assertSame('charge:fixed_price:participant:1', $stored->idempotencyKey);
        self::assertNull($stored->valueDate);
        self::assertNull($stored->paidAt);
        self::assertSame('2026-11-30 08:00:00.123456', $stored->recordedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $stored->recordedAt->getTimezone()->getName());

        $row = $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3');
        self::assertSame('48000.00000000', $row['amount'] ?? null);
        self::assertSame('HUF', $row['currency'] ?? null);
        self::assertSame('2026-11-30 08:00:00.123456', $row['recorded_at'] ?? null);
        self::assertIsArray($row);
        self::assertNull($row['value_date']);
        self::assertNull($row['paid_at']);

        self::assertEquals([$registration], $touched);
        $entries = $this->entries();
        $entry = end($entries);
        self::assertIsArray($entry);
        self::assertSame(['registration/5/12A3'], $this->subjectsOf($entry['id']));
        self::assertSame('[]', $entry['changes']);
    }

    public function testALineOfARegistrationTheRunDoesNotHoldIsASystemFailureAndWritesNothing(): void
    {
        $registration = $this->registration();
        $line = $this->line($registration, LineKind::Charge, '1000', 'k1');

        try {
            $this->inRun(fn (array $input, Context $context): LedgerLine => $this->ledger->record($line, $context));
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertSame('The run does not hold the registration.', $caught->getPrevious()?->getMessage());
        }
        self::assertSame(0, $this->lineCount());

        $stored = $this->inRun(function (array $input, Context $context) use ($line): LedgerLine {
            $this->lockRegistrations($context, $line->registration);

            return $this->ledger->record($line, $context);
        });

        self::assertInstanceOf(LedgerLine::class, $stored);
        self::assertSame(1, $this->lineCount());
    }

    public function testAManualPaymentKeepsItsFractionAndValueDate(): void
    {
        $registration = $this->registration();
        $recorded = $this->record(
            $this->line($registration, LineKind::ManualPayment, '-12500.5', 'pay:1', '2026-12-03')
        )[0];

        self::assertSame('-12500.50000000', $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3')['amount'] ?? null);
        self::assertSame(
            '2026-12-02 23:00:00.000000',
            $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3')['value_date'] ?? null
        );

        $read = (new LedgerLineRepository())->find($recorded->id);
        self::assertInstanceOf(LedgerLine::class, $read);
        self::assertTrue($read->amount->isEqualTo($this->money('-12500.5')));
        self::assertNotNull($read->valueDate);
        self::assertSame('2026-12-02 23:00:00.000000', $read->valueDate->format('Y-m-d H:i:s.u'));
        self::assertNull($read->paidAt);
    }

    public function testAPaidTimeIsStoredInUtcWithMicroseconds(): void
    {
        $registration = $this->registration();
        $paid = new \DateTimeImmutable('2026-12-01 11:00:00.250000', new \DateTimeZone('Europe/Budapest'));
        $line = new NewLine(
            $registration,
            LineKind::Payment,
            'participant',
            $this->money('-100'),
            new Subject('payment', 7, 'abc'),
            'payment:abc',
            null,
            $paid
        );

        $recorded = $this->record($line)[0];

        self::assertSame(
            '2026-12-01 10:00:00.250000',
            $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3')['paid_at'] ?? null
        );
        self::assertNotNull($recorded->paidAt);
        self::assertSame('2026-12-01 10:00:00.250000', $recorded->paidAt->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $recorded->paidAt->getTimezone()->getName());
        self::assertSame(7, $recorded->source->eventId);
    }

    public function testRecordingTheSameLineTwiceInOneRunWritesOneRow(): void
    {
        $registration = $this->registration();
        $line = $this->line($registration, LineKind::Charge, '1000', 'k1');

        [$first, $second] = $this->record($line, $line);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $this->lineCount());
    }

    public function testRecordingTheSameLineInTwoRunsWritesOneRowAndTouchesNothingTheSecondTime(): void
    {
        $registration = $this->registration();
        $line = $this->line($registration, LineKind::ManualPayment, '-1000', 'k1', '2026-12-03');
        $first = $this->record($line)[0];
        $entries = count($this->entries());

        $touched = null;
        $second = $this->inRun(function (array $input, Context $context) use ($line, &$touched): LedgerLine {
            $this->lockRegistrations($context, $line->registration);
            $stored = $this->ledger->record($line, $context);
            $touched = $context->touchedRegistrations();

            return $stored;
        });

        self::assertInstanceOf(LedgerLine::class, $second);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, $this->lineCount());
        self::assertSame([], $touched);
        $all = $this->entries();
        self::assertCount($entries + 1, $all);
        self::assertSame([], $this->subjectsOf($all[$entries]['id']));
    }

    /** @return iterable<string, array{string}> */
    public static function differentLines(): iterable
    {
        yield 'amount' => ['amount'];
        yield 'kind' => ['kind'];
        yield 'payer' => ['payer'];
        yield 'source' => ['source'];
        yield 'value date' => ['value date'];
        yield 'source type' => ['source type'];
        yield 'source event' => ['source event'];
        yield 'paid at' => ['paid at'];
    }

    /** @dataProvider differentLines */
    public function testTheSameKeyWithADifferentLineIsASystemFailureAndStoresNothingMore(string $difference): void
    {
        $registration = $this->registration();
        $stored = $difference === 'paid at'
            ? $this->line($registration, LineKind::Payment, '-1000', 'k1', null, '2026-12-01 10:00:00')
            : $this->line($registration, LineKind::ManualPayment, '-1000', 'k1', '2026-12-03');
        $this->record($stored);

        $other = match ($difference) {
            'amount' => $this->line($registration, LineKind::ManualPayment, '-1001', 'k1', '2026-12-03'),
            'kind' => $this->line($registration, LineKind::Transfer, '-1000', 'k1', '2026-12-03'),
            'payer' => $this->line(
                $registration,
                LineKind::ManualPayment,
                '-1000',
                'k1',
                '2026-12-03',
                null,
                'sponsor'
            ),
            'source' => $this->line(
                $registration,
                LineKind::ManualPayment,
                '-1000',
                'k1',
                '2026-12-03',
                null,
                'participant',
                'other'
            ),
            'source type' => $this->line(
                $registration,
                LineKind::ManualPayment,
                '-1000',
                'k1',
                '2026-12-03',
                null,
                'participant',
                'fixed_price',
                'other_component'
            ),
            'source event' => $this->line(
                $registration,
                LineKind::ManualPayment,
                '-1000',
                'k1',
                '2026-12-03',
                null,
                'participant',
                'fixed_price',
                'fee_component',
                7
            ),
            'paid at' => $this->line($registration, LineKind::Payment, '-1000', 'k1', null, '2026-12-01 10:01:00'),
            default => $this->line($registration, LineKind::ManualPayment, '-1000', 'k1', '2026-12-04'),
        };

        $this->assertRefused($other, 1);
    }

    public function testTheSameKeyOnAnotherRegistrationOfTheEventIsASecondRow(): void
    {
        $first = $this->registration('12A3');
        $second = $this->registration('12K0');

        $this->record(
            $this->line($first, LineKind::Charge, '1000', 'k1'),
            $this->line($second, LineKind::Charge, '1000', 'k1')
        );

        self::assertSame(2, $this->lineCount());
    }

    public function testKeysThatDifferOnlyInPunctuationAreSeparateRows(): void
    {
        $registration = $this->registration();
        $lines = [];
        foreach (['ab', 'a.b', 'a-b', 'a_b', 'a:b'] as $key) {
            $lines[] = $this->line($registration, LineKind::Charge, '1000', $key);
        }

        $stored = $this->record(...$lines);

        self::assertSame(5, $this->lineCount());
        self::assertCount(5, array_unique(array_map(static fn (LedgerLine $line): int => $line->id, $stored)));
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield '129 characters' => [str_repeat('a', 129)];
        yield 'upper case' => ['Abc'];
        yield 'space' => ['a b'];
        yield 'newline at the end' => ["ab\n"];
        yield 'accent' => ['á'];
    }

    /** @dataProvider badKeys */
    public function testAMalformedKeyIsASystemFailureAndStoresNothing(string $key): void
    {
        $this->assertRefused($this->line($this->registration(), LineKind::Charge, '1000', $key));
    }

    public function testAKeyOf128CharactersIsStored(): void
    {
        $this->record($this->line($this->registration(), LineKind::Charge, '1000', str_repeat('a', 128)));

        self::assertSame(1, $this->lineCount());
    }

    /** @return iterable<string, array{LineKind, string, ?string, ?string, string}> */
    public static function refusedLines(): iterable
    {
        yield 'payment of +1' => [LineKind::Payment, '1', null, '2026-12-01 10:00:00', 'participant'];
        yield 'refund of -1' => [LineKind::Refund, '-1', null, '2026-12-01 10:00:00', 'participant'];
        yield 'manual payment of 0' => [LineKind::ManualPayment, '0', '2026-12-03', null, 'participant'];
        yield 'charge of 0' => [LineKind::Charge, '0', null, null, 'participant'];
        yield 'payment without a paid time' => [LineKind::Payment, '-1', null, null, 'participant'];
        yield 'manual payment without a value date' => [LineKind::ManualPayment, '-1', null, null, 'participant'];
        yield 'payment with a value date' => [
            LineKind::Payment, '-1', '2026-12-03', '2026-12-01 10:00:00', 'participant',
        ];
        yield 'payment with only a value date' => [LineKind::Payment, '-1', '2026-12-03', null, 'participant'];
        yield 'manual payment with a paid time' => [
            LineKind::ManualPayment, '-1', '2026-12-03', '2026-12-01 10:00:00', 'participant',
        ];
        yield 'manual payment with only a paid time' => [
            LineKind::ManualPayment, '-1', null, '2026-12-01 10:00:00', 'participant',
        ];
        yield 'transfer without a value date' => [LineKind::Transfer, '-1', null, null, 'participant'];
        yield 'charge with a value date' => [LineKind::Charge, '1', '2026-12-03', null, 'participant'];
        yield 'charge with a paid time' => [LineKind::Charge, '1', null, '2026-12-01 10:00:00', 'participant'];
        yield 'adjustment with a value date' => [LineKind::Adjustment, '1', '2026-12-03', null, 'participant'];
        yield 'adjustment with a paid time' => [LineKind::Adjustment, '1', null, '2026-12-01 10:00:00', 'participant'];
        yield 'value date that is no day' => [LineKind::ManualPayment, '-1', '2026-02-30', null, 'participant'];
        yield 'value date in another form' => [LineKind::ManualPayment, '-1', '2026-12-3', null, 'participant'];
        yield 'payer Congregation' => [LineKind::Charge, '1', null, null, 'Congregation'];
        yield 'empty payer' => [LineKind::Charge, '1', null, null, ''];
        yield 'payer of 51 characters' => [LineKind::Charge, '1', null, null, str_repeat('a', 51)];
    }

    /** @dataProvider refusedLines */
    public function testABrokenLineIsASystemFailureAndStoresNothing(
        LineKind $kind,
        string $amount,
        ?string $valueDate,
        ?string $paidAt,
        string $payer,
    ): void {
        $this->assertRefused($this->line($this->registration(), $kind, $amount, 'k1', $valueDate, $paidAt, $payer));
    }

    /** @return iterable<string, array{LineKind, string, ?string, ?string}> */
    public static function storedLines(): iterable
    {
        yield 'charge of -5000' => [LineKind::Charge, '-5000', null, null];
        yield 'adjustment of -2000' => [LineKind::Adjustment, '-2000', null, null];
        yield 'adjustment of +2000' => [LineKind::Adjustment, '2000', null, null];
        yield 'charge of 0.004' => [LineKind::Charge, '0.004', null, null];
        yield 'manual refund of +1000' => [LineKind::ManualRefund, '1000', '2026-12-03', null];
        yield 'transfer of -1000' => [LineKind::Transfer, '-1000', '2026-12-03', null];
        yield 'transfer of +1000' => [LineKind::Transfer, '1000', '2026-12-03', null];
        yield 'refund of +1000' => [LineKind::Refund, '1000', null, '2026-12-01 10:00:00'];
    }

    /** @dataProvider storedLines */
    public function testALineThatFollowsTheRulesIsStored(
        LineKind $kind,
        string $amount,
        ?string $valueDate,
        ?string $paidAt
    ): void {
        $recorded = $this->record($this->line($this->registration(), $kind, $amount, 'k1', $valueDate, $paidAt))[0];

        self::assertSame(1, $this->lineCount());
        self::assertTrue($recorded->amount->isEqualTo($this->money($amount)));
    }

    public function testAValueDateIsStoredAsMidnightOfItsDayInTheSiteZoneAndKeepsItWhenTheZoneChanges(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $registration = $this->registration();

        $this->record($this->line($registration, LineKind::ManualPayment, '-1', 'k1', '2026-12-01'));
        update_option('timezone_string', 'Pacific/Auckland');

        self::assertSame(
            '2026-11-30 23:00:00.000000',
            $this->rowOf('kalaka_ledger_lines', self::EVENT, '12A3')['value_date'] ?? null
        );
        $fact = $this->ledger->paymentFactsOf($registration)[0];
        self::assertSame(
            '2026-11-30 23:00:00',
            $fact->valueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    private function assertRefused(NewLine $line, int $rows = 0): void
    {
        try {
            $this->record($line);
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }

        self::assertSame($rows, $this->lineCount());
    }
}
