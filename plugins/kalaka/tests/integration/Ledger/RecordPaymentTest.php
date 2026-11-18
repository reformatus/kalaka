<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\IdempotencyConflict;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Registrations\Api\RegistrationKey;

/** S22: the "record payment" use case. */
final class RecordPaymentTest extends ManualEntryTestCase
{
    public function testItRecordsOneManualPaymentLine(): void
    {
        $result = $this->exec(new RecordPayment(), $this->paymentInput(['note' => '  Teszt Elek utalása ']));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame(['id' => (int) $lines[0]['id']], $result);
        self::assertSame('manual_payment', $lines[0]['kind']);
        self::assertSame('participant', $lines[0]['payer']);
        self::assertSame('-5000.00000000', $lines[0]['amount']);
        self::assertSame('HUF', $lines[0]['currency']);
        self::assertSame('bank_transfer', $lines[0]['method']);
        self::assertSame('0', $lines[0]['correction']);
        self::assertSame('manual:k1', $lines[0]['idempotency_key']);
        // 00:00 of the day in the site time zone, as a UTC instant.
        self::assertSame('2026-11-28 23:00:00.000000', $lines[0]['value_date']);
        self::assertNull($lines[0]['paid_at']);
        self::assertSame('2026-11-30 09:00:00.000000', $lines[0]['recorded_at']);
        self::assertSame('wp_user', $lines[0]['source_type']);
        self::assertSame((string) $this->admin->userId, $lines[0]['source_key']);
    }

    /** @return array<string, array{string, string}> */
    public static function days(): array
    {
        return [
            'winter' => ['2026-01-15', '2026-01-14 23:00:00.000000'],
            'the day the clocks go forward' => ['2026-03-29', '2026-03-28 23:00:00.000000'],
            'the day after' => ['2026-03-30', '2026-03-29 22:00:00.000000'],
            'summer' => ['2026-07-01', '2026-06-30 22:00:00.000000'],
            'the day the clocks go back' => ['2026-10-25', '2026-10-24 22:00:00.000000'],
            'the day after that' => ['2026-10-26', '2026-10-25 23:00:00.000000'],
        ];
    }

    /** @dataProvider days */
    public function testTheValueDateIsMidnightOfTheDayInTheSiteZone(string $day, string $stored): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['value_date' => $day]));

        self::assertSame($stored, $this->lines()[0]['value_date']);
    }

    public function testTodayIsTheSiteDayNotTheUtcDay(): void
    {
        // 00:30 on 2026-12-01 in Budapest is still 2026-11-30 in UTC.
        $this->runner = $this->runnerAt('2026-12-01 00:30');

        $this->exec(new RecordPayment(), $this->paymentInput(['value_date' => '2026-12-01']));
        self::assertCount(1, $this->lines());

        $this->expectException(InvalidInput::class);
        $this->exec(
            new RecordPayment(),
            $this->paymentInput(['value_date' => '2026-12-02', 'idempotency_key' => 'k2'])
        );
    }

    public function testAFutureDayIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->exec(new RecordPayment(), $this->paymentInput(['value_date' => '2026-12-01']));
    }

    public function testAnyPastDayIsAccepted(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['value_date' => '2019-01-01']));

        self::assertCount(1, $this->lines());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badInputs(): array
    {
        return [
            'not a day' => [['value_date' => '2026-02-30']],
            'zero' => [['amount' => '0']],
            'zero with decimals' => [['amount' => '0.00']],
            'negative' => [['amount' => '-5']],
            'beyond the smallest unit' => [['amount' => '10.005']],
            'wrong currency' => [['currency' => 'EUR']],
            'unknown payer' => [['payer' => 'congregation']],
            'unknown method' => [['method' => 'barter']],
            'a malformed key' => [['idempotency_key' => 'Not Allowed']],
            'a key that is too long' => [['idempotency_key' => str_repeat('a', 101)]],
            'a note that is too long' => [['note' => str_repeat('á', 501)]],
            'a code that is no reference code' => [['code' => 'abc']],
            'an extra property' => [['status' => 'registered']],
        ];
    }

    /**
     * @dataProvider badInputs
     * @param array<string, mixed> $override
     */
    public function testBadInputIsRefusedAndWritesNothing(array $override): void
    {
        try {
            $this->exec(new RecordPayment(), $this->paymentInput($override));
            self::fail('The input was accepted.');
        } catch (InvalidInput) {
            self::assertSame([], $this->lines());
        }
    }

    public function testAMissingRequiredFieldIsRefused(): void
    {
        $input = $this->paymentInput();
        unset($input['method']);

        $this->expectException(InvalidInput::class);
        $this->exec(new RecordPayment(), $input);
    }

    public function testAnUnknownRegistrationIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->exec(new RecordPayment(), $this->paymentInput(['code' => '00A2']));
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->exec(new RecordPayment(), $this->paymentInput(['event_id' => $this->event + 1000]));
    }

    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        return [
            'registered' => ['registered'],
            'waitlisted' => ['waitlisted'],
            'withdrawn' => ['withdrawn'],
            'removed' => ['removed'],
        ];
    }

    /** @dataProvider statuses */
    public function testEveryStatusAcceptsAPayment(string $status): void
    {
        $this->addRegistration($this->event, '00A2', $status);

        $this->exec(new RecordPayment(), $this->paymentInput(['code' => '00A2']));

        self::assertCount(1, $this->lines());
    }

    public function testThereIsNoCapAtTheBalance(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['amount' => '99999999']));

        self::assertSame('-99999999.00000000', $this->lines()[0]['amount']);
    }

    public function testTheSameKeyAndInputWritesOnce(): void
    {
        $first = $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'ref 1']));
        $second = $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'ref 1']));

        self::assertSame($first, $second);
        self::assertCount(1, $this->lines());
        self::assertCount(1, $this->notes());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function differentInputs(): array
    {
        return [
            'an amount' => [['amount' => '5001']],
            'a method' => [['method' => 'cash']],
            'a day' => [['value_date' => '2026-11-28']],
            'a note' => [['note' => 'másik']],
        ];
    }

    /**
     * @dataProvider differentInputs
     * @param array<string, mixed> $different
     */
    public function testTheSameKeyWithDifferentInputIsAnError(array $different): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'ref 1']));

        try {
            $this->exec(new RecordPayment(), $different + $this->paymentInput(['note' => 'ref 1']));
            self::fail('A different line under the same key was accepted.');
        } catch (IdempotencyConflict $conflict) {
            self::assertSame(409, $conflict->status());
            self::assertSame('kalaka_idempotency_conflict', $conflict->errorCode());
            self::assertCount(1, $this->lines());
            self::assertCount(1, $this->notes());
            // A refusal of the person's act, audited as one: not a system error.
            $failed = array_values(array_filter(
                $this->entries(),
                static fn (array $entry): bool => $entry['outcome'] === 'failed'
            ));
            self::assertCount(1, $failed);
            self::assertStringContainsString('IdempotencyConflict', (string) $failed[0]['error']);
        }
    }

    public function testTheSameCallerKeyOnTwoRegistrationsWritesTwoLines(): void
    {
        $this->addRegistration($this->event, '00A2');
        $this->exec(new RecordPayment(), $this->paymentInput());
        $this->exec(new RecordPayment(), $this->paymentInput(['code' => '00A2']));

        self::assertCount(2, $this->lines());
    }

    public function testTheNoteIsInTheSideRecordAndNotInTheLedgerOrTheAudit(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'Teszt Elek, közlemény 1234']));

        $notes = $this->notes();
        self::assertCount(1, $notes);
        self::assertSame('Teszt Elek, közlemény 1234', $notes[0]['note']);
        self::assertSame($this->lines()[0]['id'], $notes[0]['line_id']);
        self::assertSame('00A0', $notes[0]['code']);
        foreach ($this->lines() as $line) {
            self::assertStringNotContainsString('Teszt Elek', (string) wp_json_encode($line));
        }
        foreach ($this->entries() as $entry) {
            self::assertStringNotContainsString('Teszt Elek', (string) wp_json_encode($entry));
            self::assertStringNotContainsString('1234', (string) ($entry['changes'] ?? ''));
        }
    }

    public function testAnEmptyNoteWritesNoSideRecord(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['note' => '   ']));

        self::assertSame([], $this->notes());
    }

    public function testErasingTheNotesLeavesTheLine(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'Teszt']));
        $key = new RegistrationKey($this->event, '00A0');

        $erased = $this->runner->run(
            new \Kalaka\Tests\Integration\Foundation\AdminProbe(
                function (array $input, Context $context, int $call) use ($key): int {
                    $this->lockRegistrations($context, $key);

                    return (new Ledger())->eraseNotesOf($key, $context);
                }
            ),
            [],
            new SystemActor()
        );

        self::assertSame(1, $erased);
        self::assertSame([], $this->notes());
        self::assertCount(1, $this->lines());
    }

    public function testTheLineIsAPaymentFactAndTheBalanceMoves(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['amount' => '1200.5']));

        $balances = (new Ledger())->balancesOf(new RegistrationKey($this->event, '00A0'));
        self::assertSame('-1200.50000000', $balances->total(Currency::HUF)->amount());
        $facts = (new Ledger())->paymentFactsOf(new RegistrationKey($this->event, '00A0'));
        self::assertCount(1, $facts);
    }

    public function testTheTouchRunsTheEvaluation(): void
    {
        // A price saved with no touch: nothing is charged until the registration is evaluated.
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
        self::assertSame([], $this->lines());

        $this->exec(new RecordPayment(), $this->paymentInput());

        $kinds = array_column($this->lines(), 'kind');
        self::assertContains('charge', $kinds, 'The touch ran the evaluation, which materialized the price.');
        self::assertContains('manual_payment', $kinds);
    }

    public function testTheAuditEntryHoldsWhatWasRecordedAndNoNote(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(['note' => 'Teszt titok']));

        $entry = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['action'] === 'kalaka/record-payment') {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('succeeded', $entry['outcome']);
        $changes = (string) $entry['changes'];
        self::assertStringContainsString('manual_payment', $changes);
        self::assertStringContainsString('bank_transfer', $changes);
        self::assertStringNotContainsString('titok', $changes);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id']));
    }

    public function testNoGrantAndAGrantOfAnotherActionAreRefused(): void
    {
        $user = $this->plainUser();
        try {
            $this->exec(new RecordPayment(), $this->paymentInput(), new UserActor($user));
            self::fail('No grant, and it ran.');
        } catch (PermissionDenied) {
            self::assertSame([], $this->lines());
        }

        $this->grantOn($user, ['kalaka_record_refund']);
        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordPayment(), $this->paymentInput(), new UserActor($user));
    }

    public function testAGrantWhoseViewExcludesTheRegistrationIsRefused(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment'], 'waitlisted');

        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordPayment(), $this->paymentInput(), new UserActor($user));
    }

    public function testAGrantWithTheActionInsideItsViewRecords(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);

        $this->exec(new RecordPayment(), $this->paymentInput(), new UserActor($user));

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame((string) $user, $lines[0]['source_key']);
    }

    public function testAGrantHolderCannotLearnWhetherAnUnknownCodeExists(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);

        $this->expectException(PermissionDenied::class);
        $this->exec(new RecordPayment(), $this->paymentInput(['code' => '00A2']), new UserActor($user));
    }

    public function testASystemRunRecordsWithTheSystemAsSource(): void
    {
        $this->exec(new RecordPayment(), $this->paymentInput(), new SystemActor());

        self::assertSame('system', $this->lines()[0]['source_type']);
    }
}
